#!/usr/bin/env bash
# Mostra grants da conta de leitura sem tocar nos registos.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "Falta deploy/.env" >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
echo "Privilégios da conta cis_reader (não apresenta passwords):"
"${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B -e "$1"' _ "SHOW GRANTS FOR 'cis_reader'@'%';"
echo "A conta deve ter SELECT apenas nas tabelas autorizadas; qualquer privilégio global/escrita deve ser revogado antes da integração."
