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
nonce="$(openssl rand -hex 5)"
db="cis_cash_opkey_db_$nonce"
network="cis_cash_opkey_net_$nonce"
volume="cis_cash_opkey_vol_$nonce"
image_name="cis-cash-opkey:$nonce"
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
echo '=== ENSAIO ISOLADO DA IDEMPOTENCIA DO EXTRACTO ==='
echo "Backup: $(basename "$backup")"
echo "SHA256: $(sha256sum "$backup" | cut -d' ' -f1)"
sudo docker network create --internal "$network" >/dev/null; net_created=1
sudo docker volume create "$volume" >/dev/null; vol_created=1
sudo docker run -d --name "$db" --network "$network" --network-alias cash-opkey-mysql \
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
# A origem é obrigatoriamente um backup da Caixa directa já existente.
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='cis_cash_accounts';")" == 1 ]] || { echo 'Backup anterior à Caixa directa; ensaio recusado.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id' AND IS_NULLABLE='YES';")" == 1 ]] || { echo 'Sem Extracto.account_id nullable; recusado.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND COLUMN_NAME='operation_key';")" == 0 ]] || { echo 'operation_key existente; não repetir.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cis' AND TABLE_NAME IN ('cis_cash_links','cis_cash_movements');")" == 0 ]] || { echo 'Tabelas paralelas existentes; bloqueado.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND INDEX_NAME='cis_extracto_operation_key_uq';")" == 0 ]] || { echo 'Indice já existente.' >&2; exit 1; }
before="$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0),COALESCE(SUM(`Nr`),0) FROM `Extracto`;')"
contracts="$(sql 'SELECT COUNT(*) FROM Contractos;')"
accounts="$(sql 'SELECT COUNT(*),COALESCE(SUM(opening_balance),0) FROM cis_cash_accounts;')"
associated="$(sql 'SELECT COUNT(*) FROM Extracto WHERE account_id IS NOT NULL;')"
echo "Extracto antes: $before; Contractos: $contracts; Contas: $accounts; Associados: $associated"
echo 'A compilar imagem actualizada com a migração exacta...'
sudo docker build --quiet -t "$image_name" backend >/dev/null; image_created=1
echo 'A executar APENAS a nova migração no MySQL ISOLADO...'
sudo docker run --rm --network "$network" --entrypoint php \
 -e APP_ENV=production -e APP_DEBUG=false -e "APP_KEY=$app_key" \
 -e CIS_RUN_MIGRATIONS=false -e DB_CONNECTION=mysql \
 -e DB_HOST=cash-opkey-mysql -e DB_PORT=3306 -e DB_DATABASE=cis \
 -e DB_USERNAME=root -e "DB_PASSWORD=$password" \
 "$image_name" artisan migrate \
 --path=database/migrations/2026_11_17_000050_add_operation_key_to_extracto.php --force
echo 'A verificar valores, índices e dados históricos...'
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0),COALESCE(SUM(`Nr`),0) FROM `Extracto`;')" == "$before" ]] || { echo 'DIVERGENCIA Extracto.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*) FROM Contractos;')" == "$contracts" ]] || { echo 'DIVERGENCIA Contractos.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(opening_balance),0) FROM cis_cash_accounts;')" == "$accounts" ]] || { echo 'DIVERGENCIA contas.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*) FROM Extracto WHERE account_id IS NOT NULL;')" == "$associated" ]] || { echo 'DIVERGENCIA atribuições.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*) FROM Extracto WHERE operation_key IS NOT NULL;')" == 0 ]] || { echo 'Identidades históricas alteradas.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND COLUMN_NAME='operation_key' AND IS_NULLABLE='YES' AND CHARACTER_MAXIMUM_LENGTH=120;")" == 1 ]] || { echo 'Coluna incorreta.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND INDEX_NAME='cis_extracto_operation_key_uq' AND NON_UNIQUE=0;")" == 1 ]] || { echo 'Indice UNIQUE não existe.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM migrations WHERE migration='2026_11_17_000050_add_operation_key_to_extracto';")" == 1 ]] || { echo 'Migração não registada.' >&2; exit 1; }
echo 'ENSAIO DE operation_key APROVADO: backup restaurado, dados intactos, UNIQUE nullable confirmado.'
