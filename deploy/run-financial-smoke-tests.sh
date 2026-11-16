#!/usr/bin/env bash
# Testes de cálculo e lint sem efectuar transacções financeiras reais.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: falta deploy/.env' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.api-test.yaml)
echo '=== Testes financeiros isolados (sem escrita) ==='
echo 'A compilar a imagem da API de testes...'
"${COMPOSE[@]}" build api-test
for test_file in \
  contract_opening_pricing_smoke.php \
  contract_calculation_strategy_smoke.php \
  financial_workflows_smoke.php
do
 echo "TESTE: $test_file"
 case "$test_file" in
  contract_opening_pricing_smoke.php) marker='OK: sete estratégias/casos' ;;
  contract_calculation_strategy_smoke.php) marker='OK: seis estratégias compatíveis' ;;
  financial_workflows_smoke.php) marker='OK: calendários semanais' ;;
 esac
 output="$("${COMPOSE[@]}" run --rm --no-deps api-test php "tests/$test_file" 2>&1)" || { printf '%s\n' "$output"; echo "FALHA: $test_file (exit code)" >&2; exit 1; }
 printf '%s\n' "$output"
 grep -Fq "$marker" <<< "$output" || { echo "FALHA: $test_file terminou sem marcador de sucesso." >&2; exit 1; }
done
echo '=== Sintaxe dos componentes financeiros PHP ==='
for source in \
  app/Services/LegacyContractPricing.php \
  app/Services/ContractCalculationStrategy.php \
  app/Http/Controllers/Api/ContractOpeningController.php \
  app/Http/Controllers/Api/ContractCollectionController.php \
  app/Http/Controllers/Api/ContractOperationalStatusController.php
do
 echo "LINT: $source"
 "${COMPOSE[@]}" run --rm --no-deps api-test php -l "$source"
done
echo 'TESTES CONCLUÍDOS: nenhuma activação efectuada. Nenhuma operação financeira criada.'
