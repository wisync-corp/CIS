#!/usr/bin/env bash
# Pré-visualização React+Laravel limitada a 127.0.0.1 do VPS.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: deploy/.env em falta" >&2; exit 1; }
if ! grep -Eq '^APP_KEY=base64:[A-Za-z0-9+/=]{40,}$' deploy/.env; then
  echo "ERRO: APP_KEY permanente em falta ou mal formatada." >&2
  exit 1
fi
if ! command -v docker >/dev/null; then
  echo "ERRO: Docker indisponível." >&2
  exit 1
fi
sudo docker compose --env-file deploy/.env \
  -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml \
  up -d --build mysql api-preview web-preview
echo "Pré-visualização pronta no LOOPBACK do VPS: http://127.0.0.1:8080"
echo "No PowerShell do PC: ssh -N -L 8080:127.0.0.1:8080 cisadmin@192.236.188.156"
echo "Depois abra no PC: http://localhost:8080"
echo "Não abra 8080 no firewall, não use o IP público e não use para dados financeiros."
