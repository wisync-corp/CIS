#!/usr/bin/env bash
# Diagnostico SEGURO, somente leitura, sem nomes, BI, numeros de cliente nem datas individuais.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: deploy/.env em falta"; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql(){ "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 -N -B "$MYSQL_DATABASE" -e "$1"' _ "$1"; }
echo '=== TIPOS TEMPORAIS ACTUAIS ==='
sql "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND COLUMN_NAME IN ('Data de Emissão','validade_documento','melhor_horario_contacto') ORDER BY ORDINAL_POSITION;"
echo '=== CONTAGEM DE CLIENTES ==='
sql 'SELECT COUNT(*) FROM `Clientes`;'
echo '=== DATAS LEGADAS ZERO (não mostra linhas) ==='
sql "SELECT COUNT(*) FROM \`Clientes\` WHERE CAST(\`Data de Emissão\` AS CHAR) LIKE '0000-00-00%';"
echo '=== RESUMO DOS CAMPOS NOVOS (não mostra valores) ==='
sql "SELECT
 SUM(CASE WHEN validade_documento IS NULL THEN 1 ELSE 0 END) AS validade_nula,
 SUM(CASE WHEN melhor_horario_contacto IS NULL OR TRIM(CAST(melhor_horario_contacto AS CHAR))='' THEN 0 ELSE 1 END) AS horarios_preenchidos
 FROM \`Clientes\`;"
echo 'Nenhum registo ou tipo de coluna foi modificado.'
