#!/usr/bin/env bash
# Testa POST login, GET me e POST logout via Laravel HTTP kernel, sem publicar portas.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: falta deploy/.env." >&2; exit 1; }
if ! grep -Eq '^APP_KEY=base64:[A-Za-z0-9+/=]{40,}$' deploy/.env; then
  echo "ERRO: APP_KEY persistente em falta ou formato inválido." >&2
  exit 1
fi
printf 'Email institucional do administrador CIS: '
IFS= read -r email
if [[ ! "$email" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
  echo "ERRO: email inválido." >&2
  exit 1
fi
echo "O teste pode revogar sessões pré-existentes. Nenhuma porta pública será aberta."
sudo docker compose --env-file deploy/.env \
  -f deploy/mysql-only.yaml -f deploy/compose.api-test.yaml \
  run --rm --no-deps --build api-test php artisan cis:test-http-login "$email"
