#!/usr/bin/env bash
# Ensaiar corrigenda em BACKUP ACTUAL (depois da migração antiga),
# num MySQL e numa rede Docker isolados. Nenhuma escrita no MySQL real.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
backup="${1:-}"
if [[ -z "$backup" ]]; then
  backup_dir="$HOME/cis-private/backups"
  [[ -d "$backup_dir" ]] || { echo 'BLOQUEADO: sem pasta privada de backups.' >&2; exit 1; }
  backup="$(find "$backup_dir" -maxdepth 1 -type f -name 'cis-before-auth-*.sql' -printf '%T@ %p\n' | sort -rn | head -n 1 | cut -d' ' -f2-)"
fi
[[ -n "$backup" && -r "$backup" && -s "$backup" ]] || { echo 'Backup ausente, vazio ou ilegível.' >&2; exit 1; }
command -v openssl >/dev/null || exit 1
sudo -v # pedir autenticação uma única vez antes de iniciar o ensaio
nonce="$(openssl rand -hex 5)"
db="cis_cash_close_db_$nonce"
network="cis_cash_close_net_$nonce"
volume="cis_cash_close_vol_$nonce"
image_name="cis-cash-close:$nonce"
password="$(openssl rand -hex 32)"
app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
db_created=0; net_created=0; vol_created=0; image_created=0
cleanup() {
  result=$?
  trap - EXIT
  if [[ "$db_created" == 1 ]]; then sudo -n docker rm -f "$db" >/dev/null 2>&1 || true; fi
  if [[ "$net_created" == 1 ]]; then sudo -n docker network rm "$network" >/dev/null 2>&1 || true; fi
  if [[ "$vol_created" == 1 ]]; then sudo -n docker volume rm "$volume" >/dev/null 2>&1 || true; fi
  if [[ "$image_created" == 1 ]]; then sudo -n docker image rm "$image_name" >/dev/null 2>&1 || true; fi
  if [[ "$result" -eq 0 ]]; then
    echo 'ENSAIO DO FECHO DIARIO APROVADO; base real intocada.'
  else
    echo 'ENSAIO DO FECHO DIARIO REPROVADO. NAO mexer na base real.' >&2
  fi
  exit "$result"
}
trap cleanup EXIT
echo '=== ENSAIO ISOLADO DA FECHO DIARIO DA CAIXA ==='
echo "Backup: $(basename "$backup")"
echo "SHA256: $(sha256sum "$backup" | cut -d' ' -f1)"
sudo -n docker network create --internal "$network" >/dev/null; net_created=1
sudo -n docker volume create "$volume" >/dev/null; vol_created=1
sudo -n docker run -d --name "$db" --network "$network" --network-alias cash-close-mysql \
 --mount "type=volume,src=$volume,dst=/var/lib/mysql" \
 -e "MYSQL_ROOT_PASSWORD=$password" -e MYSQL_DATABASE=cis mysql:8.0 >/dev/null; db_created=1
ready=0
for i in $(seq 1 90); do
 if sudo -n docker exec -e "MYSQL_PWD=$password" "$db" mysql -uroot -N -e 'SELECT 1;' >/dev/null 2>&1; then ready=1; break; fi
 sleep 2
done
[[ "$ready" == 1 ]] || { echo 'MySQL temporario indisponivel.' >&2; exit 1; }
echo 'A restaurar copia num MySQL temporario...'
sudo -n docker exec -i -e "MYSQL_PWD=$password" "$db" mysql -uroot --default-character-set=utf8mb4 cis < "$backup"
sql() {
 sudo -n docker exec -e "MYSQL_PWD=$password" "$db" mysql -uroot --batch --skip-column-names cis -e "$1"
}

[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='cis_cash_accounts';")" == 1 ]] || { echo 'Sem contas da Caixa.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id';")" == 1 ]] || { echo 'Sem account_id.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND COLUMN_NAME='operation_key';")" == 1 ]] || { echo 'Backup anterior à operação: criar backup actual.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='cis_cash_daily_closures';")" == 0 ]] || { echo 'Fechos já existem; criação duplicada proibida.' >&2; exit 1; }
financial="$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0),COALESCE(SUM(`Nr`),0) FROM Extracto;')"
contracts="$(sql 'SELECT COUNT(*) FROM Contractos;')"
accounts="$(sql 'SELECT COUNT(*),COALESCE(SUM(opening_balance),0) FROM cis_cash_accounts;')"
echo "Extracto antes: $financial | Contractos: $contracts | Contas: $accounts"
sudo -n docker build --quiet -t "$image_name" backend >/dev/null; image_created=1
sudo -n docker run --rm --network "$network" --entrypoint php \
 -e APP_ENV=production -e APP_DEBUG=false -e "APP_KEY=$app_key" \
 -e CIS_RUN_MIGRATIONS=false -e DB_CONNECTION=mysql \
 -e DB_HOST=cash-close-mysql -e DB_PORT=3306 -e DB_DATABASE=cis \
 -e DB_USERNAME=root -e "DB_PASSWORD=$password" \
 "$image_name" artisan migrate \
 --path=database/migrations/2026_11_17_000060_create_cis_cash_daily_closures.php --force
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='cis_cash_daily_closures' AND ENGINE='InnoDB';")" == 1 ]] || { echo 'Tabela de fechos não criada.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='cis_cash_daily_closures' AND INDEX_NAME='cis_cash_close_account_date_uq' AND NON_UNIQUE=0;")" == 2 ]] || { echo 'Índice único não conforme.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*) FROM cis_cash_daily_closures;')" == 0 ]] || { echo 'Fechos fictícios detectados.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0),COALESCE(SUM(`Nr`),0) FROM Extracto;')" == "$financial" ]] || { echo 'Extracto mudou.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*) FROM Contractos;')" == "$contracts" ]] || { echo 'Contratos mudaram.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(opening_balance),0) FROM cis_cash_accounts;')" == "$accounts" ]] || { echo 'Contas mudaram.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM migrations WHERE migration='2026_11_17_000060_create_cis_cash_daily_closures';")" == 1 ]] || { echo 'Migração não registada.' >&2; exit 1; }
echo 'A testar gravação documental do fecho APENAS no MySQL isolado...'
sudo -n docker run --rm --network "$network" --entrypoint php \
 -e APP_ENV=production -e APP_DEBUG=false -e "APP_KEY=$app_key" \
 -e DB_CONNECTION=mysql -e DB_HOST=cash-close-mysql -e DB_PORT=3306 \
 -e DB_DATABASE=cis -e DB_USERNAME=root -e "DB_PASSWORD=$password" \
 -e CASH_CLOSURES_DB_USERNAME=root -e "CASH_CLOSURES_DB_PASSWORD=$password" \
 -e CIS_ISOLATED_REHEARSAL=true -e CIS_PRIVATE_PREVIEW=true \
 -e CIS_CASH_DAILY_CLOSE_APPROVED=true -e CIS_RUN_MIGRATIONS=false \
 "$image_name" tests/cash_daily_closure_mysql_rehearsal.php
[[ "$(sql 'SELECT COUNT(*) FROM cis_cash_daily_closures;')" == 0 ]] || { echo "O ensaio deixou fechos temporários." >&2; exit 1; }
proof_dir="$HOME/cis-private/cash-verification"
mkdir -p "$proof_dir"
chmod 700 "$proof_dir"
sha256sum backend/database/migrations/2026_11_17_000060_create_cis_cash_daily_closures.php | awk '{print $1}' > "$proof_dir/daily-closure-migration.sha256"
chmod 600 "$proof_dir/daily-closure-migration.sha256"
echo 'ENSAIO DO FECHO DIÁRIO APROVADO: controlo independente, números financeiros intactos.'
