#!/bin/sh
set -eu
mkdir -p /srv/cis/storage/framework/cache /srv/cis/storage/framework/sessions /srv/cis/storage/framework/views /srv/cis/database
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
  touch /srv/cis/database/database.sqlite
fi
if [ -z "${APP_KEY:-}" ]; then
  echo "ERRO: APP_KEY não configurada. Gere uma chave e guarde-a no ficheiro .env privado." >&2
  exit 1
fi
if [ "${APP_ENV:-local}" != "production" ] || [ "${CIS_RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force
else
  echo "CIS: migrações automáticas desactivadas em produção; base importada preservada."
fi
exec "$@"
