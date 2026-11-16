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
  backup="$(find "$backup_dir" -maxdepth 1 -type f -name 'cis-before-auth-*.sql' -printf '%T@ %p\\n' | sort -rn | head -n 1 | cut -d' ' -f2-)"
fi
[[ -n "$backup" && -r "$backup" && -s "$backup" ]] || { echo 'Backup ausente, vazio ou ilegível.' >&2; exit 1; }
command -v openssl >/dev/null || exit 1
nonce="$(openssl rand -hex 5)"
db="cis_cash_repair_db_$nonce"
network="cis_cash_repair_net_$nonce"
volume="cis_cash_repair_vol_$nonce"
image_name="cis-cash-repair:$nonce"
password="$(openssl rand -hex 32)"
app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
db_created=0; net_created=0; vol_created=0; image_created=0
cleanup() {
  result=$?
  trap - EXIT
  if [[ "$db_created" == 1 ]]; then sudo docker rm -f "$db" >/dev/null 2>&1 || true; fi
  if [[ "$net_created" == 1 ]]; then sudo docker network rm "$network" >/dev/null 2>&1 || true; fi
  if [[ "$vol_created" == 1 ]]; then sudo docker volume rm "$volume" >/dev/null 2>&1 || true; fi
  if [[ "$image_created" == 1 ]]; then sudo docker image rm "$image_name" >/dev/null 2>&1 || true; fi
  if [[ "$result" -eq 0 ]]; then
    echo 'ENSAIO DE CORRECCAO APROVADO; base real intocada.'
  else
    echo 'ENSAIO DE CORRECCAO REPROVADO. NAO mexer na base real.' >&2
  fi
  exit "$result"
}
trap cleanup EXIT
echo '=== ENSAIO ISOLADO DA CORRECCAO DA CAIXA ==='
echo "Backup: $(basename "$backup")"
echo "SHA256: $(sha256sum "$backup" | cut -d' ' -f1)"
sudo docker network create --internal "$network" >/dev/null; net_created=1
sudo docker volume create "$volume" >/dev/null; vol_created=1
sudo docker run -d --name "$db" --network "$network" --network-alias cash-repair-mysql \
 --mount "type=volume,src=$volume,dst=/var/lib/mysql" \
 -e "MYSQL_ROOT_PASSWORD=$password" -e MYSQL_DATABASE=cis mysql:8.0 >/dev/null; db_created=1
ready=0
for i in $(seq 1 90); do
 if sudo docker exec -e "MYSQL_PWD=$password" "$db" mysql -uroot -N -e 'SELECT 1;' >/dev/null 2>&1; then ready=1; break; fi
 sleep 2
done
[[ "$ready" == 1 ]] || { echo 'MySQL temporario indisponivel.' >&2; exit 1; }
echo 'A restaurar copia num MySQL temporario...'
sudo docker exec -i -e "MYSQL_PWD=$password" "$db" mysql -uroot --default-character-set=utf8mb4 cis < "$backup"
sql() {
 sudo docker exec -e "MYSQL_PWD=$password" "$db" mysql -uroot --batch --skip-column-names cis -e "$1"
}
# Confirmar que se trata exactamente da versao antiga vazia.
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='cis_cash_accounts';")" == 1 ]]
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='cis_cash_movements';")" == 1 ]]
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id';")" == 0 ]]
[[ "$(sql 'SELECT COUNT(*) FROM cis_cash_accounts;')" == 0 ]]
[[ "$(sql 'SELECT COUNT(*) FROM cis_cash_movements;')" == 0 ]]
[[ "$(sql "SELECT COUNT(*) FROM migrations WHERE migration='2026_11_16_000030_create_cis_cash_tables';")" == 1 ]]
before="$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0) FROM `Extracto`;')"
contracts="$(sql 'SELECT COUNT(*) FROM Contractos;')"
echo "Extracto antes: $before; Contractos: $contracts"
echo 'A compilar backend actualizado de teste...'
sudo docker build --quiet -t "$image_name" backend >/dev/null; image_created=1
echo 'A executar APENAS a migration correctiva no restauro...'
sudo docker run --rm --network "$network" --entrypoint php \
 -e APP_ENV=production -e APP_DEBUG=false -e "APP_KEY=$app_key" \
 -e CIS_RUN_MIGRATIONS=false -e DB_CONNECTION=mysql \
 -e DB_HOST=cash-repair-mysql -e DB_PORT=3306 -e DB_DATABASE=cis \
 -e DB_USERNAME=root -e "DB_PASSWORD=$password" \
 "$image_name" artisan migrate \
 --path=database/migrations/2026_11_17_000032_repair_legacy_cash_schema_to_direct_accounts.php --force
echo 'A confirmar que nenhum valor financeiro foi alterado...'
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0) FROM `Extracto`;')" == "$before" ]]
[[ "$(sql 'SELECT COUNT(*) FROM Contractos;')" == "$contracts" ]]
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='cis_cash_movements';")" == 0 ]]
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='cis_cash_accounts' AND COLUMN_NAME='opening_extracto_nr';")" == 1 ]]
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id' AND IS_NULLABLE='YES';")" == 1 ]]
[[ "$(sql 'SELECT COUNT(*) FROM `Extracto` WHERE account_id IS NOT NULL;')" == 0 ]]
[[ "$(sql "SELECT COUNT(*) FROM migrations WHERE migration='2026_11_17_000032_repair_legacy_cash_schema_to_direct_accounts';")" == 1 ]]
echo "Extracto depois: $(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0) FROM `Extracto`;')"
echo 'Resultado: uma tabela cis_cash_accounts + Extracto.account_id, sem cis_cash_movements.'
