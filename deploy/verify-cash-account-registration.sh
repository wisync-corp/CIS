#!/usr/bin/env bash
# Recuperar da falha de verificação (sem repetir CREATE USER/GRANT).
# Recompila a API corrigida, efectua somente consultas e não cria contas.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: deploy/.env em falta.' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
DBCOMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql() {
  printf '%s\n' "$1" | "${DBCOMPOSE[@]}" exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'
}
echo '=== RECUPERAR VERIFICACAO DO CADASTRO CAIXA ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || { echo 'BLOQUEADO: base inesperada.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id';")" == 1 ]] || { echo 'BLOQUEADO: Extracto.account_id em falta.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_movements';")" == 0 ]] || { echo 'BLOQUEADO: estrutura antiga ainda existe.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM mysql.user WHERE User='cis_cash_setup';")" == 1 ]] || { echo 'BLOQUEADO: utilizador dedicado não existe; não criar automaticamente.' >&2; exit 1; }
grep -qx 'CIS_CASH_ACCOUNT_SETUP_APPROVED=true' deploy/.env || { echo 'BLOQUEADO: cadastro não está autorizado no .env privado.' >&2; exit 1; }
grep -q '^CASH_SETUP_DB_PASSWORD=' deploy/.env || { echo 'BLOQUEADO: credencial privada não existe.' >&2; exit 1; }
if grep -qx 'CIS_CASH_WRITES_APPROVED=true' deploy/.env; then echo 'BLOQUEADO: escrita de caixa geral não deveria estar activa.' >&2; exit 1; fi

echo 'A reconstruir e reiniciar apenas o backend corrigido...'
"${COMPOSE[@]}" up -d --no-deps --build --force-recreate api-preview

echo 'A verificar configuração Laravel e ligação dedicada (SELECT sem dados pessoais)...'
"${COMPOSE[@]}" exec -T api-preview php -r '
require "/srv/cis/vendor/autoload.php";
$app=require "/srv/cis/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!array_key_exists("mysql_cash_setup",config("database.connections",[]))) {
  fwrite(STDERR,"BLOQUEADO: Ligação mysql_cash_setup não registada em database.connections.\n");
  exit(2);
}
if (!filter_var(env("CIS_CASH_ACCOUNT_SETUP_APPROVED",false),FILTER_VALIDATE_BOOLEAN)) exit(3);
if (filter_var(env("CIS_CASH_WRITES_APPROVED",false),FILTER_VALIDATE_BOOLEAN)) exit(4);
$writer=\Illuminate\Support\Facades\DB::connection("mysql_cash_setup");
if ($writer->getDatabaseName()!=="cis") exit(5);
$writer->select("SELECT id FROM cis_cash_accounts LIMIT 0");
echo "OK: ligação exclusiva de cadastro acessível em cis; nenhuma escrita efectuada.\n";
'
echo 'A verificar permissões MySQL da conta dedicada...'
grants="$(sql "SHOW GRANTS FOR 'cis_cash_setup'@'%';")"
if [[ "$grants" != *'cis_cash_accounts'* ]] || [[ "$grants" != *'INSERT'* ]]; then
  echo 'BLOQUEADO: não foi confirmado INSERT na tabela cis_cash_accounts.' >&2
  exit 1
fi
if [[ "$grants" == *'Extracto'* ]] || [[ "$grants" == *'ALL PRIVILEGES'* ]] || [[ "$grants" == *'UPDATE'* ]] || [[ "$grants" == *'DELETE'* ]]; then
  echo 'BLOQUEADO: privilégios excessivos detectados para o utilizador de cadastro.' >&2
  exit 1
fi
echo 'CADASTRO CAIXA VERIFICADO: conexão MySQL configurada e privilégios restritos.'
echo 'Saídas, transferências, desembolsos e validação de saldos continuam BLOQUEADOS.'
