#!/usr/bin/env bash
# Diagnostico POS-migracao da Caixa. SOMENTE SELECT / information_schema.
# Nao chama migrate, nao modifica dados, nao reverte schema e nao altera flags.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: deploy/.env inexistente." >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql() {
  printf '%s\n' "$1" | "${COMPOSE[@]}" exec -T mysql sh -c     'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'
}
echo "=== POS-MIGRACAO CAIXA: DIAGNOSTICO APENAS DE LEITURA ==="
schema="$(sql 'SELECT DATABASE();')"
echo "Esquema: $schema"
[[ "$schema" == cis ]] || { echo 'BLOQUEADO: base inesperada.' >&2; exit 1; }
check() {
  local label="$1" query="$2" expected="$3" value
  value="$(sql "$query")" || { echo "ERRO SQL: $label" >&2; return 1; }
  echo "$label = $value (esperado: $expected)"
  [[ "$value" == "$expected" ]] || { echo "FALHOU: $label" >&2; return 1; }
}
failed=0
check 'cis_cash_accounts existe' "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts';" 1 || failed=1
check 'Extracto.account_id existe e e nullable' "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id' AND IS_NULLABLE='YES';" 1 || failed=1
check 'cis_cash_links ausente' "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_links';" 0 || failed=1
check 'cis_cash_movements ausente' "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_movements';" 0 || failed=1
check 'migracao registada' "SELECT COUNT(*) FROM migrations WHERE migration='2026_11_16_000030_create_cis_cash_tables';" 1 || failed=1
if [[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id';")" == 1 ]]; then
  check 'Extracto historico nao associado' 'SELECT COUNT(*) FROM `Extracto` WHERE account_id IS NOT NULL;' 0 || failed=1
fi
if [[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts';")" == 1 ]]; then
  check 'contas ficticias nao criadas' 'SELECT COUNT(*) FROM cis_cash_accounts;' 0 || failed=1
fi
extract_summary="$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0) FROM `Extracto`;')"
contract_summary="$(sql 'SELECT COUNT(*) FROM `Contractos`;')"
echo "Extracto: $extract_summary"
echo "Contractos: $contract_summary"
echo 'O historico de referencia do ultimo ensaio era: Extracto 1295; Contractos 680.'
echo 'Se as contagens divergirem, investigar OPERACOES LEGITIMAS desde o backup; nao assumir perda automaticamente.'
if [[ "$failed" != 0 ]]; then
  echo 'POS-MIGRACAO: INCONSISTENCIA DETECTADA. Nao executar migrations nem rollback.' >&2
  exit 1
fi
echo 'POS-MIGRACAO ESTRUTURAL: APROVADO (somente leitura).'
echo 'Este teste NAO activa pagamentos, transferencias, cobrancas ou desembolsos.'
