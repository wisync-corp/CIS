#!/usr/bin/env bash
# Operação facultativa e explicitamente aprovada após os testes de fórmulas.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: deploy/.env em falta.' >&2; exit 1; }
grep -qx 'CIS_NEW_CONTRACTS_APPROVED=true' deploy/.env || {
 echo 'BLOQUEADO: não foi autorizada abertura de contratos reais neste servidor.' >&2; exit 1;
}
if grep -qx 'CIS_EXTENDED_CALCULATIONS_APPROVED=true' deploy/.env; then
 echo 'Estratégias adicionais já activadas.'
 exit 0
fi
if grep -q '^CIS_EXTENDED_CALCULATIONS_APPROVED=' deploy/.env; then
 echo 'BLOQUEADO: configuração já existe. Rever manualmente sem duplicar.' >&2
 exit 1
fi
echo 'A executar os testes dos cálculos novos sem modificar a base de dados…'
sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.api-test.yaml run --rm --no-deps --build api-test php tests/contract_opening_pricing_smoke.php
sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.api-test.yaml run --rm --no-deps api-test php tests/contract_calculation_strategy_smoke.php
echo 'ATENÇÃO: fórmulas adicionais foram definidas agora pelo CIS, e não transcritas do VBA.'
echo 'Valores arredondados sempre para cima a meticais inteiros. O capital total continua igual ao desembolso.'
echo 'Isto afecta SOMENTE novos contratos criados depois da activação, não os antigos.'
read -r -p 'Confirma que os resultados dos testes foram revistos? Escreva APROVAR-FORMULAS: ' confirm
[[ "$confirm" == 'APROVAR-FORMULAS' ]] || { echo 'Cancelado.'; exit 0; }
umask 077
printf '\nCIS_EXTENDED_CALCULATIONS_APPROVED=true\n' >> deploy/.env
chmod 600 deploy/.env
echo 'Estratégias adicionais autorizadas neste ambiente. Recrie api-preview para aplicar.'
