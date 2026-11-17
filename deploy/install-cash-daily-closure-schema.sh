#!/usr/bin/env bash
# Migração aditiva do fecho diário, NÃO habilita escrita.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || exit 1
sudo -v
exec 9>/tmp/cis-cash-close-install.lock
flock -n 9 || { echo "Instalação concorrente bloqueada." >&2; exit 1; }
proof="$HOME/cis-private/cash-verification/daily-closure-migration.sha256"
file=backend/database/migrations/2026_11_17_000060_create_cis_cash_daily_closures.php
[[ -s "$proof" && -s "$file" ]] || { echo "Falta ensaio isolado aprovado." >&2; exit 1; }
sha="$(sha256sum "$file" | cut -d" " -f1)"
[[ "$sha" == "$(cat "$proof")" ]] || { echo "Migração mudou após ensaio." >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf "%s\n" "$1" | timeout 35 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'; }
[[ "$(sql "SELECT DATABASE();")" == cis ]] || exit 1
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_daily_closures';")" == 0 ]] || { echo "Tabela já existe. Não repetir." >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='operation_key';")" == 1 ]] || { echo "Esquema incompleto." >&2; exit 1; }
if grep -qx "CIS_CASH_DAILY_CLOSE_APPROVED=true" deploy/.env; then echo "Fechos já activos; bloqueado." >&2; exit 1; fi
financial="$(sql "SELECT COUNT(*),COALESCE(SUM(Valor),0) FROM Extracto;")"
contracts="$(sql "SELECT COUNT(*) FROM Contractos;")"
accounts="$(sql "SELECT COUNT(*),COALESCE(SUM(opening_balance),0) FROM cis_cash_accounts;")"
"${APP[@]}" build api-preview
image_sha="$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview /srv/cis/database/migrations/2026_11_17_000060_create_cis_cash_daily_closures.php | cut -d" " -f1)"
[[ "$sha" == "$image_sha" ]] || { echo "API desactualizada." >&2; exit 1; }
bash deploy/backup-before-auth.sh
read -r -p "Confirma criar apenas tabela de controlo, sem activar fechos? Digite INSTALAR-FECHO-CAIXA: " answer
[[ "$answer" == INSTALAR-FECHO-CAIXA ]] || exit 0
"${APP[@]}" run --rm --no-deps api-preview php artisan migrate --path=database/migrations/2026_11_17_000060_create_cis_cash_daily_closures.php --force
[[ "$(sql "SELECT COUNT(*) FROM cis_cash_daily_closures;")" == 0 ]] || { echo "Fechos inesperados." >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*),COALESCE(SUM(Valor),0) FROM Extracto;")" == "$financial" ]] || { echo "Extracto alterado." >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM Contractos;")" == "$contracts" ]] || { echo "Contractos alterados." >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*),COALESCE(SUM(opening_balance),0) FROM cis_cash_accounts;")" == "$accounts" ]] || { echo "Contas alteradas." >&2; exit 1; }
echo "TABELA DE FECHOS INSTALADA. Escrita de fechos continua DESACTIVADA."
