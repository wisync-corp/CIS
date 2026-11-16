#!/usr/bin/env bash
# Activar SOMENTE cadastro de contas; nunca saldos, pagamentos ou desembolsos.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env em falta.' >&2; exit 1; }
umask 077
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql() {
  printf '%s\n' "$1" | "${COMPOSE[@]}" exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'
}
assert_eq() {
  [[ "$(sql "$1")" == "$2" ]] || { echo "BLOQUEADO: $3" >&2; exit 1; }
}
echo 'A verificar a arquitectura DIRECTA da Caixa...'
assert_eq 'SELECT DATABASE();' cis 'base de dados inesperada.'
assert_eq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts';" 1 'cis_cash_accounts em falta.'
assert_eq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_movements';" 0 'tabela antiga ainda existe.'
assert_eq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id';" 1 'Extracto.account_id em falta.'
assert_eq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts' AND COLUMN_NAME='opening_extracto_nr';" 1 'opening_extracto_nr em falta.'
assert_eq "SELECT COUNT(*) FROM migrations WHERE migration='2026_11_17_000032_repair_legacy_cash_schema_to_direct_accounts';" 1 'migração correctiva não confirmada.'
if grep -qx 'CIS_CASH_ACCOUNT_SETUP_APPROVED=true' deploy/.env; then
  echo 'Cadastro já activo. Não alterar automaticamente; rever configuração do servidor.'
  exit 0
fi
if grep -qx 'CIS_CASH_WRITES_APPROVED=true' deploy/.env; then
  echo 'BLOQUEADO: escrita financeira geral estava activa; corrigir manualmente antes de prosseguir.' >&2; exit 1
fi
if grep -q '^CASH_SETUP_DB_PASSWORD=' deploy/.env; then
  echo 'BLOQUEADO: há credenciais anteriores para cadastro. Rever privilégios antes de repetir.' >&2; exit 1
fi
assert_eq "SELECT COUNT(*) FROM mysql.user WHERE User='cis_cash_setup';" 0 'utilizador cis_cash_setup já existe. Rever antes de criar.'
echo 'Activação proposta: utilizador exclusivo com INSERT/SELECT somente em cis_cash_accounts.'
echo 'Nenhum INSERT em Extracto; nenhum saldo inicial; nenhuma activação bancária.'
read -r -p 'Autoriza o CADASTRO de contas (sem saldos nem transacções)? Digite ACTIVAR-CADASTRO-CAIXA: ' approval
[[ "$approval" == 'ACTIVAR-CADASTRO-CAIXA' ]] || { echo 'Cancelado sem criar utilizador ou alterar configuração.'; exit 0; }
secret="$(openssl rand -hex 32)"
sql "CREATE USER 'cis_cash_setup'@'%' IDENTIFIED BY '$secret'; GRANT SELECT, INSERT ON \`cis\`.\`cis_cash_accounts\` TO 'cis_cash_setup'@'%';"
{
  printf '\nCASH_SETUP_DB_USERNAME=cis_cash_setup\n'
  printf 'CASH_SETUP_DB_PASSWORD=%s\n' "$secret"
  printf 'CIS_CASH_ACCOUNT_SETUP_APPROVED=true\n'
} >> deploy/.env
chmod 600 deploy/.env
echo 'A aplicar a nova configuração à API, sem activar movimentos de crédito...'
sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml up -d --no-deps --build --force-recreate api-preview web-preview
echo 'A verificar conexão do utilizador de cadastro e privilégios restritos...'
sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml exec -T api-preview php -r '
require "/srv/cis/vendor/autoload.php";
$app=require "/srv/cis/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!filter_var(env("CIS_CASH_ACCOUNT_SETUP_APPROVED",false),FILTER_VALIDATE_BOOLEAN)) exit(2);
if (filter_var(env("CIS_CASH_WRITES_APPROVED",false),FILTER_VALIDATE_BOOLEAN)) exit(3);
$conn=\Illuminate\Support\Facades\DB::connection("mysql_cash_setup");
if ($conn->getDatabaseName()!=="cis") exit(4);
$conn->select("SELECT id FROM cis_cash_accounts LIMIT 0");
echo "CADASTRO DE CONTAS CONFIGURADO. Movimentos financeiros bloqueados.\n";
'
echo 'Cadastro disponível no menu Caixa para administrador autenticado.'
