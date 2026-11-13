#!/usr/bin/env bash
# Read-only preflight before connecting Laravel to the imported MySQL.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "Falta deploy/.env" >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
read -r -d '' SQL <<'SQL' || true
SELECT TABLE_NAME, COUNT(*) AS columns_present
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('Usuarios','Clientes','Contractos','Extracto')
GROUP BY TABLE_NAME ORDER BY TABLE_NAME;
SELECT TABLE_NAME
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users','personal_access_tokens','migrations','cache','jobs','clients')
ORDER BY TABLE_NAME;
SQL
echo "Estrutura do legado e possíveis tabelas Laravel (apenas metadados):"
"${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$1"' _ "$SQL"
echo "Não foi executada qualquer migração Laravel nem alteração à base."
echo "Antes de iniciar a API, é necessário definir credenciais separadas, verificar autorização institucional e rever migrações."
