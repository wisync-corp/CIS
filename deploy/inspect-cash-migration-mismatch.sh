#!/usr/bin/env bash
# Diagnostico da instalacao divergente. ESTRITAMENTE LEITURA.
# Nao remover tabelas, nao executar migrations, nao alterar dados.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: deploy/.env nao encontrado.' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql() {
  printf '%s\n' "$1" | "${COMPOSE[@]}" exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'
}
echo '=== INVESTIGACAO CAIXA: APENAS LEITURA ==='
echo "Base: $(sql 'SELECT DATABASE();')"
echo 'Estruturas actuais:'
sql "SELECT TABLE_NAME, ENGINE, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('Extracto','cis_cash_accounts','cis_cash_movements','cis_cash_links','migrations') ORDER BY TABLE_NAME;"
echo 'Colunas da tabela cis_cash_accounts:'
sql "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts' ORDER BY ORDINAL_POSITION;"
echo 'Colunas da tabela cis_cash_movements:'
sql "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_movements' ORDER BY ORDINAL_POSITION;"
echo 'Colunas da tabela Extracto relevantes para Caixa:'
sql "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME IN ('Nr','account_id') ORDER BY ORDINAL_POSITION;"
echo 'Contagem EXACTA de linhas nas estruturas de Caixa:'
cash_accounts="$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts';")"
cash_movements="$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_movements';")"
cash_links="$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_links';")"
if [[ "$cash_accounts" == 1 ]]; then
  echo "cis_cash_accounts: $(sql 'SELECT COUNT(*) FROM cis_cash_accounts;') linha(s)"
fi
if [[ "$cash_movements" == 1 ]]; then
  echo "cis_cash_movements: $(sql 'SELECT COUNT(*) FROM cis_cash_movements;') linha(s)"
fi
if [[ "$cash_links" == 1 ]]; then
  echo "cis_cash_links: $(sql 'SELECT COUNT(*) FROM cis_cash_links;') linha(s)"
fi
echo 'Estado da migration Caixa:'
sql "SELECT migration, batch FROM migrations WHERE migration='2026_11_16_000030_create_cis_cash_tables';"
echo 'Resumo financeiro (sem dados pessoais):'
sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0) FROM `Extracto`;'
sql 'SELECT COUNT(*) FROM `Contractos`;'
echo 'INSPECCAO CONCLUIDA. NENHUM DADO ALTERADO.'
echo 'Nao executar DROP, DELETE, migrate, migrate:rollback nem install-cash-direct-account.sh.'
