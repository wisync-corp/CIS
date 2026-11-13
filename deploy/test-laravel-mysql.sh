#!/usr/bin/env bash
# Teste isolado do Laravel contra MySQL, apenas SELECT e sem portas públicas.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: falta deploy/.env" >&2; exit 1; }
command -v openssl >/dev/null || { echo "ERRO: openssl indisponível" >&2; exit 1; }
# Chave efémera apenas para executar o teste; não activa autenticação nem migrações.
export APP_KEY="base64:$(openssl rand -base64 32)"
echo "A compilar e executar uma verificação Laravel isolada (sem portas públicas, sem migrações)."
sudo --preserve-env=APP_KEY docker compose --env-file deploy/.env \
  -f deploy/mysql-only.yaml -f deploy/compose.api-test.yaml \
  run --rm --no-deps --build api-test
