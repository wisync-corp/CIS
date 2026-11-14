#!/usr/bin/env bash
# Diagnóstico somente leitura: estados de contrato, sem nomes, contratos ou dados pessoais.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: ficheiro deploy/.env em falta" >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql() {
 "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 -N -B "$MYSQL_DATABASE" -e "$1"' _ "$1"
}
echo "=== Estados originais: Estado do Contrato ==="
sql "SELECT COALESCE(NULLIF(TRIM(\`Estado do Contrato\`),''),'(sem estado)'),COUNT(*)
 FROM \`Contractos\`
 GROUP BY COALESCE(NULLIF(TRIM(\`Estado do Contrato\`),''),'(sem estado)')
 ORDER BY 1;"
echo "=== Estado consolidado por contrato (sem números de contratos) ==="
sql "SELECT estado, COUNT(*) FROM (
 SELECT \`Nr do Contracto\`,
 CASE WHEN COUNT(DISTINCT COALESCE(NULLIF(TRIM(\`Estado do Contrato\`),''),'__CIS_VAZIO__'))>1
 THEN 'Estados divergentes'
 ELSE COALESCE(MAX(NULLIF(TRIM(\`Estado do Contrato\`),'')),'Sem estado') END AS estado
 FROM \`Contractos\` GROUP BY \`Nr do Contracto\`
 ) agrupados GROUP BY estado ORDER BY estado;"
echo "=== Estados de PRESTAÇÕES (campo diferente) ==="
sql "SELECT COALESCE(NULLIF(TRIM(\`Estado\`),''),'(sem estado)'),COUNT(*)
 FROM \`Contractos\`
 GROUP BY COALESCE(NULLIF(TRIM(\`Estado\`),''),'(sem estado)')
 ORDER BY 1;"
echo 'Diagnóstico concluído. Nenhum dado alterado.'
