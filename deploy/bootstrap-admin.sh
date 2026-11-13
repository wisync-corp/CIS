#!/usr/bin/env bash
# Cria o primeiro administrador CIS na mesma base mysql, sem publicar a API.
# Nunca regista passwords ou tokens no terminal / GitHub.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: deploy/.env em falta." >&2; exit 1; }
if ! grep -Eq '^APP_KEY=base64:[A-Za-z0-9+/=]{40,}$' deploy/.env; then
  echo "ERRO: configure uma APP_KEY Laravel persistente em deploy/.env." >&2
  echo "Nao use uma chave efemera para contas reais." >&2
  exit 1
fi
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql="SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','personal_access_tokens','migrations') ORDER BY TABLE_NAME;"
found=$("${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$1"' _ "$sql")
if [[ "$found" != "$(printf 'migrations\npersonal_access_tokens\nusers')" ]]; then
  echo "ERRO: as tabelas de autenticacao ainda nao estao completas:" >&2
  printf '%s\n' "$found"
  echo "Reveja o resultado da migracao antes de criar um administrador." >&2
  exit 1
fi
printf 'Email institucional do primeiro administrador CIS: '
IFS= read -r email
if [[ ! "$email" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
  echo "ERRO: email invalido." >&2
  exit 1
fi
echo "A iniciar comando interactivo Laravel com acesso privado ao MySQL."
echo "A palavra-passe sera solicitada sem mostrar o texto digitado."
sudo docker compose --env-file deploy/.env \
  -f deploy/mysql-only.yaml -f deploy/compose.api-test.yaml \
  run --rm --no-deps --build api-test php artisan cis:bootstrap-admin "$email"
echo "Verifique os administradores registados (apenas dados de conta):"
sudo docker compose --env-file deploy/.env \
  -f deploy/mysql-only.yaml -f deploy/compose.api-test.yaml \
  run --rm --no-deps api-test php artisan cis:list-admins
