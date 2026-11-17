#!/usr/bin/env bash
# Apenas leitura: verifica se a Caixa tem validadores separados e as
# permissões nao permitem lançar movimentos financeiros.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
DBC=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){
 printf '%s\n' "$1" | "${DBC[@]}" exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'
}
if ! grep -qx 'CIS_CASH_OPENING_SETUP_APPROVED=true' deploy/.env ||
   ! grep -qx 'CIS_CASH_OPENING_VALIDATE_APPROVED=true' deploy/.env; then
 echo 'ABERTURA: ainda nao activada no deploy/.env.'; exit 0
fi
[[ "$(sql "SELECT COUNT(*) FROM mysql.user WHERE User IN ('cis_cash_open_submit','cis_cash_open_validate');")" == 2 ]] || { echo 'BLOQUEADO: faltam escritores segregados.' >&2; exit 1; }
for user in cis_cash_open_submit cis_cash_open_validate; do
 grants="$(sql "SHOW GRANTS FOR '$user'@'%';")"
 [[ "$grants" == *cis_cash_accounts* ]] || { echo "BLOQUEADO: falta acesso restrito ($user)." >&2; exit 1; }
 [[ "$grants" != *Extracto* && "$grants" != *'ALL PRIVILEGES'* && "$grants" != *'GRANT INSERT'* && "$grants" != *'GRANT DELETE'* ]] || { echo "BLOQUEADO: privilegio excessivo ($user)." >&2; exit 1; }
done
"${APP[@]}" exec -T api-preview php -r '
require "/srv/cis/vendor/autoload.php";
$app=require "/srv/cis/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach(["mysql_cash_opening_submit","mysql_cash_opening_validate"] as $name) {
 $db=\Illuminate\Support\Facades\DB::connection($name);
 if($db->getDatabaseName()!=="cis")exit(2);
 $db->select("SELECT id FROM cis_cash_accounts LIMIT 0");
}
if(filter_var(env("CIS_CASH_WRITES_APPROVED",false),FILTER_VALIDATE_BOOLEAN))exit(3);
echo "OK: ligacoes de abertura e validacao respondem, sem lancamentos financeiros.\n";
'
echo 'ABERTURA DE SALDOS: VALIDADA (apenas workflow; nao comprova extractos externos).'
