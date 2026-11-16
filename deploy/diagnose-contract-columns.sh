#!/usr/bin/env bash
# Diagnostico de metadados MySQL CIS: apenas leitura, sem dados pessoais.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: deploy/.env inexistente' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
echo '=== CIS: esquema, codificacao e nomes reais de colunas (sem dados pessoais) ==='
"${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --table "$MYSQL_DATABASE"' <<'SQL'
SELECT DATABASE() AS Base_Activa, @@character_set_server AS Charset_Servidor, @@character_set_connection AS Charset_Conexao;
SELECT TABLE_NAME, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('Contractos','Extracto','Clientes')
ORDER BY TABLE_NAME;
SELECT ORDINAL_POSITION AS Posicao, COLUMN_NAME AS Nome_Coluna,
       HEX(COLUMN_NAME) AS Nome_UTF8_Hex, DATA_TYPE AS Tipo
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos'
ORDER BY ORDINAL_POSITION;
SELECT ORDINAL_POSITION AS Posicao, COLUMN_NAME AS Nome_Coluna,
       HEX(COLUMN_NAME) AS Nome_UTF8_Hex
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto'
ORDER BY ORDINAL_POSITION;
SELECT COUNT(*) AS Quantidade_Contractos FROM Contractos;
SELECT COUNT(*) AS Quantidade_Extracto FROM Extracto;
SELECT
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos'
      AND HEX(COLUMN_NAME)='416D6F7274697A61C3A7C3A36F') AS Amortizacao_UTF8_Exacta,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos'
      AND COLUMN_NAME='Capital') AS Capital_Existe,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos'
      AND COLUMN_NAME='Valor Pago') AS Valor_Pago_Existe;
SQL
echo '=== FIM: nenhuma alteracao efectuada ==='
