#!/usr/bin/env bash
# Pré-validação depois da migração; não activa fluxos, não altera a base.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: falta deploy/.env' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql(){ printf '%s\n' "$1" | "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
echo '=== Pré-validação após correcção de coluna ==='
[[ "$(sql 'SELECT DATABASE();')" == 'cis' ]] || { echo 'ERRO: base activa incorrecta'; exit 1; }
schema="$(sql "SELECT CONCAT(
 SUM(CASE WHEN COLUMN_NAME='Estratégia de Cálculo' THEN 1 ELSE 0 END), ':',
 SUM(CASE WHEN HEX(COLUMN_NAME)='457374726174C383C2A96769612064652043C383C2A16C63756C6F' THEN 1 ELSE 0 END))
 FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos';")" || exit 1
[[ "$schema" == '1:0' ]] || { echo "ERRO: esquema inesperado ($schema; esperado 1:0)"; exit 1; }
echo 'OK: coluna Estratégia de Cálculo correcta e duplicada ausente.'
neg="$(sql 'SELECT COUNT(*) FROM `Contractos` WHERE `Valor Pago` < 0 OR `Capital` < 0 OR `Amortização` < 0;')" || exit 1
[[ "$neg" =~ ^[0-9]+$ ]] || { echo 'ERRO: contagem inválida'; exit 1; }
echo "Linhas com valores financeiros negativos: $neg"
[[ "$neg" == 0 ]] || { echo 'BLOQUEADO: verificar valores negativos'; exit 1; }
echo 'Verificação de sintaxe do script de activação:'
bash -n deploy/activate-financial-workflows.sh
echo 'OK: Bash válido.'
echo 'Ficheiros esperados pelo procedimento de activação:'
missing=0
for path in \
  deploy/backup-before-auth.sh \
  deploy/compose.api-test.yaml \
  deploy/compose.preview.yaml \
  backend/tests/contract_opening_pricing_smoke.php \
  backend/tests/contract_calculation_strategy_smoke.php \
  backend/tests/financial_workflows_smoke.php \
  backend/app/Services/LegacyContractPricing.php \
  backend/app/Http/Controllers/Api/ContractOpeningController.php \
  backend/app/Http/Controllers/Api/ContractCollectionController.php \
  backend/app/Http/Controllers/Api/ContractOperationalStatusController.php
do
  if [[ -f "$path" ]]; then echo "OK: $path"; else echo "EM FALTA: $path"; missing=1; fi
done
[[ "$missing" == 0 ]] || { echo 'BLOQUEADO: corrigir ficheiros em falta antes de activar'; exit 1; }
echo 'PRÉ-VALIDAÇÃO CONCLUÍDA. Nenhuma configuração foi activada.'
