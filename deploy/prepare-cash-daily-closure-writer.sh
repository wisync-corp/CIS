#!/usr/bin/env bash
# Preparar utilizador técnico mínimo da Caixa (fechos). Não activar a gravação.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || exit 1
sudo -v
exec 9>/tmp/cis-cash-close-writer.lock
flock -n 9 || { echo "Preparação em curso." >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf "%s\n" "$1" | timeout 35 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'; }
[[ "$(sql "SELECT DATABASE();")" == cis ]] || exit 1
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_daily_closures';")" == 1 ]] || { echo "Tabela de fechos em falta." >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM mysql.user WHERE User='cis_cash_closures';")" == 0 ]] || { echo "Utilizador de fechos já existe. Verificar, não repetir." >&2; exit 1; }
if grep -Eq "^(CASH_CLOSURES_DB_PASSWORD|CIS_CASH_DAILY_CLOSE_APPROVED)=" deploy/.env; then echo "Configuração de fechos existente; inspecção necessária." >&2; exit 1; fi
read -r -p "Preparar escritor restrito de fechos, mantendo gravação DESACTIVADA? Digite PREPARAR-ESCRITOR-FECHOS: " approval
[[ "$approval" == PREPARAR-ESCRITOR-FECHOS ]] || exit 0
bash deploy/backup-before-auth.sh
password="$(openssl rand -hex 32)"
sql "CREATE USER 'cis_cash_closures'@'%' IDENTIFIED BY '$password';
GRANT SELECT ON cis.cis_cash_accounts TO 'cis_cash_closures'@'%';
GRANT UPDATE (updated_at) ON cis.cis_cash_accounts TO 'cis_cash_closures'@'%';
GRANT SELECT ON cis.Extracto TO 'cis_cash_closures'@'%';
GRANT SELECT, INSERT ON cis.cis_cash_daily_closures TO 'cis_cash_closures'@'%';"
{
  printf "\nCASH_CLOSURES_DB_USERNAME=cis_cash_closures\n"
  printf "CASH_CLOSURES_DB_PASSWORD=%s\n" "$password"
  printf "CIS_CASH_DAILY_CLOSE_APPROVED=false\n"
} >> deploy/.env
chmod 600 deploy/.env
"${APP[@]}" up -d --no-deps --build --force-recreate api-preview
echo "Escritor de fechos preparado sem UPDATE/DELETE no Extracto ou na tabela de fechos. Gravação continua DESACTIVADA."
