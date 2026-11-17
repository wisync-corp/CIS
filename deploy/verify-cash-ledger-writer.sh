#!/usr/bin/env bash
# Apenas leitura de contas, grants e estado da escrituração Caixa.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql() {
 printf '%s\n' "$1" | timeout 35 "${DB[@]}" exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'
}
echo '=== VERIFICAÇÃO DO ESCRITOR FINANCEIRO (SOMENTE LEITURA) ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || exit 1
[[ "$(sql "SELECT COUNT(*) FROM mysql.user WHERE User='cis_cash_ledger';")" == 1 ]] || {
 echo 'Ainda não existe utilizador cis_cash_ledger.' >&2; exit 1
}
grants="$(sql "SHOW GRANTS FOR 'cis_cash_ledger'@'%';")"
[[ "$grants" == *cis_cash_accounts* && "$grants" == *Extracto* ]] || {
 echo 'BLOQUEADO: grants de contas/Extracto não identificados.' >&2; exit 1
}
if [[ "$grants" == *'ALL PRIVILEGES'* ]] || echo "$grants" | grep -Eq 'GRANT (UPDATE|DELETE|ALTER|DROP|CREATE|RELOAD).*Extracto'; then
 echo 'BLOQUEADO: escritor possui privilégios excessivos em Extracto.' >&2; exit 1
fi
# Validar através de information_schema: grants de UPDATE no Extracto devem
# continuar zero, inclusive privilégios a colunas.
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=CONCAT(CHAR(39),'cis_cash_ledger',CHAR(39),'@',CHAR(39),'%',CHAR(39)) AND TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND PRIVILEGE_TYPE IN ('UPDATE','DELETE','ALTER','DROP');")" == 0 ]] || {
 echo 'BLOQUEADO: privilégios de alteração/remoção detectados em Extracto.' >&2; exit 1
}
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE=CONCAT(CHAR(39),'cis_cash_ledger',CHAR(39),'@',CHAR(39),'%',CHAR(39)) AND TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND PRIVILEGE_TYPE='UPDATE';")" == 0 ]] || {
 echo 'BLOQUEADO: UPDATE por coluna em Extracto.' >&2; exit 1
}
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE=CONCAT(CHAR(39),'cis_cash_ledger',CHAR(39),'@',CHAR(39),'%',CHAR(39)) AND TABLE_SCHEMA='cis' AND TABLE_NAME='cis_cash_accounts' AND PRIVILEGE_TYPE='UPDATE' AND COLUMN_NAME<>'updated_at';")" == 0 ]] || {
 echo 'BLOQUEADO: escritor permite alteração de campos de saldo/estado de conta.' >&2; exit 1
}
echo 'Permissões de escrituração restritas; sem UPDATE/DELETE do Extracto.'
echo 'A verificar ligação MySQL configurada na API...'
"${APP[@]}" exec -T api-preview php -r '
require "/srv/cis/vendor/autoload.php";
$app=require "/srv/cis/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=\Illuminate\Support\Facades\DB::connection("mysql_cash_ledger");
if($db->getDatabaseName()!=="cis")exit(2);
$db->select("SELECT id,status,opening_balance FROM cis_cash_accounts LIMIT 0");
$db->select("SELECT Nr,account_id,operation_key FROM Extracto LIMIT 0");
echo "OK: conexão da API responde e vê o livro e as contas.\n";
'
echo 'Flags (apenas valores booleanos):'
for k in CIS_CASH_WRITES_APPROVED CIS_CASH_LEDGER_POSTING_APPROVED; do
 if grep -qx "$k=true" deploy/.env; then echo "$k=ACTIVA"; else echo "$k=DESACTIVADA"; fi
done
echo 'Verificação concluída: nenhum lançamento efectuado.'
