#!/usr/bin/env bash
# Diagnostico somente leitura de tipos temporais do cadastro (sem valores pessoais).
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: deploy/.env nao encontrado." >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
query() {
  "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 -N -B "$MYSQL_DATABASE" -e "$1"' _ "$1"
}
echo "=== Esquema (sem dados pessoais) ==="
query "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes'
AND COLUMN_NAME IN ('Data de Emissão','validade_documento','melhor_horario_contacto')
ORDER BY ORDINAL_POSITION;"
echo "=== Motor da tabela e quantidade de registos ==="
query "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes';"
query 'SELECT COUNT(*) FROM `Clientes`;'
echo "=== Resumo dos valores presentes (sem revelar datas nem nomes) ==="
for field in "Data de Emissão" "validade_documento" "melhor_horario_contacto"; do
  exists="$(query "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND COLUMN_NAME='$field';")"
  if [[ "$exists" == "1" ]]; then
    echo "$field:"
    query "SELECT COUNT(*) AS total, SUM(CASE WHEN \`$field\` IS NULL THEN 1 ELSE 0 END) AS nulos, SUM(CASE WHEN \`$field\` IS NOT NULL THEN 1 ELSE 0 END) AS preenchidos FROM \`Clientes\`;"
  else
    echo "$field: NAO EXISTE"
  fi
done
echo "Diagnostico concluido. Nenhum dado modificado."
