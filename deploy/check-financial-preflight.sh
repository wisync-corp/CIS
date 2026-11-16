#!/usr/bin/env bash
# Pré-validação financeira sem escrita, sem apresentação de dados individuais.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: falta deploy/.env' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
echo '=== VALIDAÇÃO FINANCEIRA CIS (APENAS LEITURA) ==='
"${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --table "$MYSQL_DATABASE"' <<'SQL'
SELECT DATABASE() AS Base_Activa;
SELECT
 COUNT(*) AS Total_Contractos,
 SUM(CASE WHEN `Valor Pago` < 0 THEN 1 ELSE 0 END) AS Valor_Pago_Negativo,
 SUM(CASE WHEN `Capital` < 0 THEN 1 ELSE 0 END) AS Capital_Negativo,
 SUM(CASE WHEN `Amortização` < 0 THEN 1 ELSE 0 END) AS Amortizacao_Negativa
FROM Contractos;
SELECT COLUMN_NAME AS Nome_Coluna, HEX(COLUMN_NAME) AS Hex_Coluna
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos'
  AND (COLUMN_NAME LIKE 'Estrat%' OR HEX(COLUMN_NAME) LIKE '457374726174%')
ORDER BY ORDINAL_POSITION;
SQL
echo '=== CONCLUÍDO: nenhuma operação de escrita ==='
