#!/usr/bin/env bash
# Verifica privilégios de um leitor MySQL. Não imprime segredos, nem lê dados.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "Falta deploy/.env" >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
SQL="SELECT GRANTEE, PRIVILEGE_TYPE, TABLE_NAME FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = '\x27cis_reader\x27@\x27%\x27' ORDER BY TABLE_NAME, PRIVILEGE_TYPE;"
echo "Privilégios específicos da conta cis_reader (não exibe passwords):"
"${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$1"' _ "$SQL"
echo "Verifique também SHOW GRANTS FOR 'cis_reader'@'%' dentro do MySQL."
echo "ATENÇÃO: esta verificação não certifica ausência de permissões globais ou acesso por outra identidade."
