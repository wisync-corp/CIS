#!/usr/bin/env bash
# Pos-activacao reversoes: apenas leitura, nao cria qualquer movimento.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'deploy/.env ausente.' >&2; exit 1; }
sudo -v
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
echo '=== CAIXA GERAL | ESTORNOS | VERIFICACAO SOMENTE LEITURA ==='
for k in CIS_CASH_WRITES_APPROVED CIS_CASH_LEDGER_POSTING_APPROVED CIS_CASH_REVERSALS_APPROVED; do
  if grep -Fxq "$k=true" deploy/.env; then echo "$k=ACTIVA"; else echo "$k=DESACTIVADA"; fi
done
echo 'Flag efectivamente presente na API:'
"${APP[@]}" exec -T api-preview sh -c 'if [ "${CIS_CASH_REVERSALS_APPROVED:-false}" = "true" ]; then echo ACTIVA; else echo DESACTIVADA; fi'
echo 'Rotas de previsualizacao e escrita (sem invocar estorno):'
"${APP[@]}" exec -T api-preview php artisan route:list --path=api/cis/caixa/estornos
echo 'Nenhum movimento inserido, actualizado ou removido.'
