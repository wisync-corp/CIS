#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
command -v docker >/dev/null || { echo 'Instale o Docker e Docker Compose.' >&2; exit 1; }
docker info >/dev/null 2>&1 || { echo 'Inicie o Docker.' >&2; exit 1; }
if [ ! -f backend/.env ]; then cp backend/.env.example backend/.env; fi
if grep -Eq '^APP_KEY=[[:space:]]*$' backend/.env; then
  key="$(docker run --rm php:8.3-cli php -r 'echo "base64:".base64_encode(random_bytes(32));')"
  case "$key" in base64:*) ;; *) echo 'Não foi possível gerar APP_KEY' >&2; exit 1;; esac
  sed "s|^APP_KEY=.*$|APP_KEY=$key|" backend/.env > backend/.env.tmp
  mv backend/.env.tmp backend/.env
fi
docker compose up --build -d
printf '\nAPI: http://127.0.0.1:8000/api\nSaúde: http://127.0.0.1:8000/up\n'
printf 'Criar administrador: docker compose exec cis-api php artisan cis:bootstrap-admin admin@exemplo.test\n'
