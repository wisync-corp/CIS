#!/usr/bin/env bash
# Diagnóstico de UM contrato com estados contraditórios (somente SELECT).
# Executar exclusivamente no terminal privado; nunca partilhar outros dados do mutuário.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: falta deploy/.env.' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql(){
 "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 -N -B "$MYSQL_DATABASE" -e "$1"' _ "$1"
}
echo '=== Contratos com Estado do Contrato divergente ==='
sql "SELECT \`Nr do Contracto\` AS contrato,COUNT(*) AS linhas,
 GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(\`Estado do Contrato\`),''),'(sem estado)') ORDER BY \`Estado do Contrato\` SEPARATOR ' / ') AS estados,
 COUNT(DISTINCT COALESCE(NULLIF(TRIM(\`Estado do Contrato\`),''),'__CIS_VAZIO__')) AS diferentes
 FROM \`Contractos\` GROUP BY \`Nr do Contracto\`
 HAVING diferentes>1;"
echo '=== Distribuição por estado dos contratos divergentes (sem nomes, BI ou montantes) ==='
sql "SELECT c.\`Nr do Contracto\` AS contrato,
 COALESCE(NULLIF(TRIM(c.\`Estado do Contrato\`),''),'(sem estado)') AS estado,
 COUNT(*) AS linhas
 FROM \`Contractos\` c
 JOIN (
 SELECT \`Nr do Contracto\` FROM \`Contractos\`
 GROUP BY \`Nr do Contracto\`
 HAVING COUNT(DISTINCT COALESCE(NULLIF(TRIM(\`Estado do Contrato\`),''),'__CIS_VAZIO__'))>1
 ) d ON c.\`Nr do Contracto\`=d.\`Nr do Contracto\`
 GROUP BY c.\`Nr do Contracto\`,estado ORDER BY contrato,estado;"
echo 'Nenhum dado foi alterado. Não corrija estados automaticamente: requer validação do histórico.'
