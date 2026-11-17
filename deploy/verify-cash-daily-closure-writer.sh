#!/usr/bin/env bash
# Verificação SOMENTE LEITURA do escritor restrito de fechos diários.
# Não grava fechos, não altera deploy/.env e não activa CIS_CASH_DAILY_CLOSE_APPROVED.
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
grantee="CONCAT(CHAR(39),'cis_cash_closures',CHAR(39),'@',CHAR(39),'%',CHAR(39))"
echo '=== VERIFICAÇÃO DO ESCRITOR DE FECHOS (SOMENTE LEITURA) ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || exit 1
[[ "$(sql "SELECT COUNT(*) FROM mysql.user WHERE User='cis_cash_closures';")" == 1 ]] || {
 echo 'Ainda não existe utilizador cis_cash_closures.' >&2; exit 1
}
grants="$(sql "SHOW GRANTS FOR 'cis_cash_closures'@'%';")"
[[ "$grants" == *cis_cash_daily_closures* && "$grants" == *Extracto* && "$grants" == *cis_cash_accounts* ]] || {
 echo 'BLOQUEADO: grants de fechos/Extracto/contas não identificados.' >&2; exit 1
}
[[ "$grants" != *'ALL PRIVILEGES'* ]] || { echo 'BLOQUEADO: ALL PRIVILEGES detectado.' >&2; exit 1; }
# Nenhum privilégio global ou de base de dados (apenas tabela/coluna).
[[ "$(sql "SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=$grantee;")" == 0 ]] || {
 echo 'BLOQUEADO: privilégios ao nível da base de dados.' >&2; exit 1
}
[[ "$(sql "SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE GRANTEE=$grantee AND PRIVILEGE_TYPE<>'USAGE';")" == 0 ]] || {
 echo 'BLOQUEADO: privilégios globais.' >&2; exit 1
}
# Extracto: só SELECT. Fechos: só SELECT/INSERT (imutáveis). Contas: SELECT + UPDATE(updated_at) para o lock.
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=$grantee AND TABLE_SCHEMA='cis' AND NOT (PRIVILEGE_TYPE='SELECT' OR (TABLE_NAME='cis_cash_daily_closures' AND PRIVILEGE_TYPE='INSERT'));")" == 0 ]] || {
 echo 'BLOQUEADO: privilégios de tabela além de SELECT/INSERT permitido.' >&2; exit 1
}
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=$grantee AND TABLE_SCHEMA='cis' AND TABLE_NAME NOT IN ('Extracto','cis_cash_accounts','cis_cash_daily_closures');")" == 0 ]] || {
 echo 'BLOQUEADO: acesso a tabelas fora do âmbito dos fechos.' >&2; exit 1
}
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE=$grantee AND NOT (TABLE_NAME='cis_cash_accounts' AND COLUMN_NAME='updated_at' AND PRIVILEGE_TYPE='UPDATE');")" == 0 ]] || {
 echo 'BLOQUEADO: privilégio por coluna inesperado (saldo, estado ou Extracto).' >&2; exit 1
}
echo 'Permissões restritas: Extracto só leitura; fechos sem UPDATE/DELETE.'
grep -q '^CASH_CLOSURES_DB_PASSWORD=' deploy/.env || { echo 'BLOQUEADO: palavra-passe do escritor ausente no .env.' >&2; exit 1; }
echo 'A verificar a ligação mysql_cash_closures dentro da API (transacção revertida)...'
"${APP[@]}" exec -T api-preview php -r '
require "/srv/cis/vendor/autoload.php";
$app=require "/srv/cis/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=\Illuminate\Support\Facades\DB::connection("mysql_cash_closures");
if($db->getDatabaseName()!=="cis"){fwrite(STDERR,"Base errada.\n");exit(2);}
$db->beginTransaction();
try{
 // Mesmo lock usado pelo POST de fecho; revertido sem escrita.
 $db->table("cis_cash_accounts")->orderBy("id")->limit(1)->lockForUpdate()->get(["id"]);
 $db->select("SELECT Nr,account_id,Valor FROM Extracto LIMIT 0");
 $db->select("SELECT id,account_id,closing_date FROM cis_cash_daily_closures LIMIT 0");
}finally{$db->rollBack();}
$denied=false;
try{$db->statement("UPDATE Extracto SET Valor=Valor WHERE 1=0");}catch(\Throwable $e){$denied=true;}
if(!$denied){fwrite(STDERR,"BLOQUEADO: escritor conseguiu UPDATE no Extracto.\n");exit(3);}
echo "OK: API liga com o escritor restrito; lock e leituras funcionam; UPDATE no Extracto recusado.\n";
'
if grep -qx 'CIS_CASH_DAILY_CLOSE_APPROVED=true' deploy/.env; then
 echo 'Gravação de fechos: ACTIVA'
else
 echo 'Gravação de fechos: DESACTIVADA (esperado nesta fase)'
fi
echo 'ESCRITOR DE FECHOS VERIFICADO. Nenhum fecho gravado; Extracto e Tesouraria intactos.'
