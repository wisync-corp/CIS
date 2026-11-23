#!/usr/bin/env bash
# Actualiza a pré-visualização privada da aplicação CIS.
# Não autoriza pagamentos, não cria registos nem altera permissões SQL.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: deploy/.env em falta.' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
echo 'A validar sintaxe do controlador financeiro...'
bash -n deploy/activate-financial-workflows.sh
echo 'A reconstruir a API e a interface privada com os ficheiros do GitHub...'
"${COMPOSE[@]}" up -d --no-deps --build api-preview streamlit-preview web-preview
echo 'Estado dos serviços:'
"${COMPOSE[@]}" ps api-preview streamlit-preview web-preview
echo 'A verificar rotas de contractos (sem criação de contrato)...'
"${COMPOSE[@]}" exec -T api-preview php artisan route:list --path=api/cis/contractos
echo 'A verificar resposta HTTP local do frontend (apenas loopback)...'
if curl --silent --show-error --fail --max-time 15 --output /dev/null http://127.0.0.1:8080/; then
  echo 'OK: frontend acessível no servidor, porta privada 8080.'
else
  echo 'ERRO: interface não respondeu em 127.0.0.1:8080.' >&2
  exit 1
fi
echo 'A verificar o serviço Streamlit e a protecção do Dashboard...'
"${COMPOSE[@]}" exec -T streamlit-preview python - <<'PY'
from time import sleep
from urllib.request import urlopen
from urllib.error import URLError

for attempt in range(25):
    try:
        with urlopen('http://127.0.0.1:8501/dashboard/_stcore/health', timeout=1) as response:
            if response.status == 200:
                break
    except (URLError, TimeoutError):
        if attempt == 24:
            raise
    sleep(1)
PY
status=$(curl --silent --show-error --max-time 10 --output /dev/null --write-out '%{http_code}' http://127.0.0.1:8080/dashboard/)
[[ "$status" == 401 ]] || { echo "ERRO: Dashboard sem sessão devia devolver 401 (recebido $status)." >&2; exit 1; }
echo 'PREVIEW PRONTA. Este script NÃO activa abertura, desembolso ou cobranças reais.'
echo 'No PowerShell do computador: ssh -N -L 8080:127.0.0.1:8080 cisadmin@racknerd-da21365'
echo 'Depois abrir http://localhost:8080 no navegador.'
