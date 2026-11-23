#!/usr/bin/env bash
# Permite editar toda a ficha original do mutuário (excepto Numeração, ID e utilizador associado).
# Só acrescenta permissões UPDATE por coluna à conta cis_editor já existente. Não altera dados.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "Falta deploy/.env" >&2; exit 1; }
grep -q '^CLIENT_EDITOR_DB_USERNAME=cis_editor$' deploy/.env || { echo "A conta cis_editor não está configurada: execute primeiro deploy/activate-client-edit.sh" >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
dbsql() {
 "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$1"' _ "$1"
}
[[ "$(dbsql "SELECT COUNT(*) FROM mysql.user WHERE user='cis_editor' AND host='%';")" == "1" ]] || { echo "A conta cis_editor não existe no MySQL." >&2; exit 1; }
WANT=("Nome Completo" "Bairro" "Cidade" "Provincia" "Rua" "Contacto" "Email" "Nº de BI" "Data de Emissão" "Nacionalidade" "Naturalidade" "Estado Civil" "Nº da Casa" "Nº de Quarterão" "Conduta")
cols=()
for c in "${WANT[@]}"; do
 n="$(dbsql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND COLUMN_NAME='$c';")"
 if [[ "$n" == "1" ]]; then cols+=("\`$c\`"); else echo "Aviso: coluna «$c» não existe em Clientes; ignorada."; fi
done
[[ ${#cols[@]} -gt 0 ]] || { echo "Nenhuma coluna encontrada." >&2; exit 1; }
list="$(IFS=,; echo "${cols[*]}")"
echo "A conceder UPDATE em: ${list//\`/}"
dbsql "GRANT UPDATE ($list) ON \`cis\`.\`Clientes\` TO 'cis_editor'@'%';"
echo "Feito. Protegidos (nunca editáveis): Numeração (n.º do mutuário), ID interno e utilizador associado."
echo "Actualize: bash deploy/refresh-private-preview.sh"
