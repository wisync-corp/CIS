#!/usr/bin/env bash
# Para só o React e a API de pré-visualização; preserva MySQL e volumes.
set -euo pipefail
cd "$(dirname "$0")/.."
sudo docker compose --env-file deploy/.env \
  -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml \
  stop web-preview api-preview
echo "Pré-visualização privada interrompida; MySQL preservado."
