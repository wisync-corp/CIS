#!/usr/bin/env bash
# Apenas verifica esquema e quantidades; nunca modifica dados.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "Falta deploy/.env" >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
SQL="SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('Usuarios','Clientes','Contractos','Extracto') ORDER BY TABLE_NAME;"
# A consulta inicial impede erros quando alguma tabela ainda não foi importada.
existing=$("${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$1"' _ "$SQL")
printf 'Tabelas originais detectadas (metadados; estimativas de linhas):\n%s\n' "$existing"
count=$(printf '%s\n' "$existing" | grep -c . || true)
if [[ "$count" -ne 4 ]]; then
  echo "IMPORTAÇÃO INCOMPLETA OU AINDA NÃO EXECUTADA: foram detectadas $count de 4 tabelas." >&2
  exit 1
fi
SQL_COUNTS="SELECT 'Clientes' AS tabela, COUNT(*) AS registos FROM \`Clientes\` UNION ALL SELECT 'Contractos', COUNT(*) FROM \`Contractos\` UNION ALL SELECT 'Extracto', COUNT(*) FROM \`Extracto\` UNION ALL SELECT 'Usuarios', COUNT(*) FROM \`Usuarios\`;"
echo "Contagens exactas no destino:"
"${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$1"' _ "$SQL_COUNTS"
echo "Verificação apenas de leitura concluída. Comparar as contagens com a origem antes de usar o CIS."
