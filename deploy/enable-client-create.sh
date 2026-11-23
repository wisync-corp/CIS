#!/usr/bin/env bash
# Permite abrir NOVAS fichas de mutuário a partir do CIS (Mutuários → Novo mutuário).
# Só acrescenta INSERT em Clientes à conta cis_editor já existente. Não altera dados.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "Falta deploy/.env" >&2; exit 1; }
grep -q '^CLIENT_EDITOR_DB_USERNAME=cis_editor$' deploy/.env || { echo "A conta cis_editor não está configurada: execute primeiro deploy/activate-client-edit.sh" >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
dbsql() {
 "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --default-character-set=utf8mb4 -uroot -N -B "$MYSQL_DATABASE" -e "$1"' _ "$1"
}
[[ "$(dbsql "SELECT COUNT(*) FROM mysql.user WHERE user='cis_editor' AND host='%';")" == "1" ]] || { echo "A conta cis_editor não existe no MySQL." >&2; exit 1; }
echo "Estrutura de Clientes (como o CIS vai preencher a nova ficha):"
dbsql "SELECT CONCAT('  ',COLUMN_NAME,' | ',COLUMN_TYPE,' | ',IF(IS_NULLABLE='YES','opcional',IF(COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%','OBRIGATÓRIO','tem valor por omissão')),IF(EXTRA LIKE '%auto_increment%',' | automático','')) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' ORDER BY ORDINAL_POSITION;"
echo "Últimos números de mutuário: $(dbsql "SELECT GROUP_CONCAT(n ORDER BY id DESC SEPARATOR ', ') FROM (SELECT ID id, \`Numeração\` n FROM Clientes ORDER BY ID DESC LIMIT 3) x;")"
dbsql "GRANT SELECT, INSERT ON \`cis\`.\`Clientes\` TO 'cis_editor'@'%';"
echo "Feito: cis_editor pode criar fichas. O n.º do mutuário e o ID são atribuídos pelo CIS (sequência a seguir ao último)."
echo "Actualize: bash deploy/refresh-private-preview.sh"
