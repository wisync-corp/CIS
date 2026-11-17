#!/usr/bin/env bash
# Inspecção só de leitura da idempotência de Extracto. Nenhum DDL/DML.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'deploy/.env em falta.' >&2; exit 1; }
sudo -v
DBC=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql() {
 printf '%s\n' "$1" | "${DBC[@]}" exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'
}
check() {
 local label="$1" query="$2" expected="$3" value
 value="$(sql "$query")"
 echo "$label: $value (esperado $expected)"
 [[ "$value" == "$expected" ]] || exit 1
}
echo '=== CAPTA / OPERATION_KEY / VERIFICAÇÃO SÓ LEITURA ==='
check 'Base' 'SELECT DATABASE();' cis
check 'Coluna varchar(120) nullable' "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='operation_key' AND IS_NULLABLE='YES' AND CHARACTER_MAXIMUM_LENGTH=120;" 1
check 'Índice único' "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND INDEX_NAME='cis_extracto_operation_key_uq' AND NON_UNIQUE=0;" 1
check 'Migração registada' "SELECT COUNT(*) FROM migrations WHERE migration='2026_11_17_000050_add_operation_key_to_extracto';" 1
check 'Livro paralelo ausente' "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('cis_cash_movements','cis_cash_links');" 0
echo 'Chaves de operações existentes:'
sql 'SELECT COUNT(*) FROM Extracto WHERE operation_key IS NOT NULL;'
echo 'Movimentos históricos e total actual (leitura):'
sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0) FROM Extracto;'
echo 'Verificação concluída. A existência do índice NÃO activa escrita financeira.'
