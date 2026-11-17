#!/usr/bin/env bash
# Pós-instalação de fechos: só leitura, sem activar autorizações.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || exit 1
sudo -v
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf "%s\n" "$1" | timeout 35 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'; }
echo "=== CAIXA / FECHOS / VERIFICACAO SOMENTE LEITURA ==="
[[ "$(sql "SELECT DATABASE();")" == cis ]] || exit 1
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_daily_closures' AND ENGINE='InnoDB';")" == 1 ]] || { echo "Tabela de fechos em falta." >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_daily_closures' AND INDEX_NAME='cis_cash_close_account_date_uq' AND NON_UNIQUE=0;")" == 2 ]] || { echo "UNIQUE conta/data em falta." >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM migrations WHERE migration='2026_11_17_000060_create_cis_cash_daily_closures';")" == 1 ]] || { echo "Migração não registada." >&2; exit 1; }
echo "Fechos existentes:"
sql "SELECT COUNT(*) FROM cis_cash_daily_closures;"
echo "Fechos com contas inexistentes:"
sql "SELECT COUNT(*) FROM cis_cash_daily_closures c LEFT JOIN cis_cash_accounts a ON a.id=c.account_id WHERE a.id IS NULL;"
echo "Estado de autorização no deploy/.env:"
if grep -qx "CIS_CASH_DAILY_CLOSE_APPROVED=true" deploy/.env; then echo "Gravação de fechos: ACTIVA"; else echo "Gravação de fechos: DESACTIVADA"; fi
echo "Consulta concluída. Nenhuma alteração no Extracto ou no módulo Tesouraria."
