#!/usr/bin/env bash
# Valida credenciais do primeiro administrador sem publicar API ou gerar tokens.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: falta deploy/.env." >&2; exit 1; }
if ! grep -Eq '^APP_KEY=base64:[A-Za-z0-9+/=]{40,}$' deploy/.env; then
  echo "ERRO: APP_KEY permanente em falta ou inválida." >&2
  exit 1
fi
printf 'Email institucional do administrador CIS: '
IFS= read -r email
if [[ ! "$email" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
  echo "ERRO: email inválido." >&2
  exit 1
fi
echo "Teste privado: a palavra-passe é introduzida sem aparecer no terminal."
sudo docker compose --env-file deploy/.env \
  -f deploy/mysql-only.yaml -f deploy/compose.api-test.yaml \
  run --rm --no-deps --build api-test php artisan cis:verify-admin-login "$email"
