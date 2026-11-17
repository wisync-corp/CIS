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
db="cis_cash_real_db_$nonce"
network="cis_cash_real_net_$nonce"
volume="cis_cash_real_vol_$nonce"
image_name="cis-cash-real:$nonce"
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
    echo 'ENSAIO DE POSTAGEM REAL APROVADO; base real intocada.'
  else
    echo 'ENSAIO DE POSTAGEM REAL REPROVADO. NAO mexer na base real.' >&2
  fi
  exit "$result"
}
trap cleanup EXIT
echo '=== ENSAIO ISOLADO DE MOVIMENTOS REAIS ==='
echo "Backup: $(basename "$backup")"
echo "SHA256: $(sha256sum "$backup" | cut -d' ' -f1)"
sudo -n docker network create --internal "$network" >/dev/null; net_created=1
sudo -n docker volume create "$volume" >/dev/null; vol_created=1
sudo -n docker run -d --name "$db" --network "$network" --network-alias cash-real-mysql \
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
echo 'A confirmar existência de esquema completo de contas e idempotência...'
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='cis_cash_accounts';")" == 1 ]] || { echo 'Sem contas da Caixa.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id';")" == 1 ]] || { echo 'Sem account_id.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND COLUMN_NAME='operation_key';")" == 1 ]] || { echo 'Sem operation_key; instalar e verificar primeiro.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cis' AND TABLE_NAME IN ('cis_cash_movements','cis_cash_links');")" == 0 ]] || { echo 'Encontrado livro paralelo.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND ENGINE='InnoDB';")" == 1 ]] || { echo 'Extracto não é InnoDB.' >&2; exit 1; }
echo 'A preservar indicadores exactos anteriores ao teste...'
before="$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0) FROM `Extracto`;')"
contracts="$(sql 'SELECT COUNT(*) FROM Contractos;')"
accounts="$(sql 'SELECT COUNT(*),COALESCE(SUM(opening_balance),0) FROM cis_cash_accounts;')"
echo "Antes: Extracto=$before; Contractos=$contracts; Contas=$accounts"
echo 'A construir imagem actual da API para teste isolado...'
sudo -n docker build --quiet -t "$image_name" backend >/dev/null; image_created=1
echo 'A testar código real de POST com todas as flags APENAS no container descartável...'
sudo -n docker run --rm --network "$network" --entrypoint php \
 -e APP_ENV=production -e APP_DEBUG=false -e "APP_KEY=$app_key" \
 -e DB_CONNECTION=mysql -e DB_HOST=cash-real-mysql -e DB_PORT=3306 \
 -e DB_DATABASE=cis -e DB_USERNAME=root -e "DB_PASSWORD=$password" \
 -e CASH_LEDGER_DB_USERNAME=root -e "CASH_LEDGER_DB_PASSWORD=$password" \
 -e CIS_ISOLATED_REHEARSAL=true -e CIS_PRIVATE_PREVIEW=true \
 -e CIS_CASH_WRITES_APPROVED=true -e CIS_CASH_LEDGER_POSTING_APPROVED=true \
 -e CIS_RUN_MIGRATIONS=false \
 "$image_name" tests/cash_real_mysql_rehearsal.php
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0) FROM `Extracto`;')" == "$before" ]] || { echo 'Extracto mudou durante teste isolado.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*) FROM Contractos;')" == "$contracts" ]] || { echo 'Contratos mudaram.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(opening_balance),0) FROM cis_cash_accounts;')" == "$accounts" ]] || { echo 'Contas mudaram.' >&2; exit 1; }
echo 'ENSAIO REAL APROVADO: contabilidade transaccional testada em MySQL restaurado e dados idênticos aos anteriores.'
