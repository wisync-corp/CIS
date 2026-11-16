#!/usr/bin/env bash
# Auditoria SOMENTE LEITURA antes de aplicar a arquitectura Caixa:
# uma tabela cis_cash_accounts + Extracto.account_id NULLABLE.
# Nao corre migrations, INSERT, UPDATE, ALTER nem altera deploy/.env.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: deploy/.env nao encontrado.' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql() {
  printf '%s\n' "$1" | "${COMPOSE[@]}" exec -T mysql sh -c    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'
}
echo '=== PRE-VOO CAIXA (apenas leitura) ==='
schema="$(sql 'SELECT DATABASE();')"
echo "Esquema: $schema"
[[ "$schema" == 'cis' ]] || { echo 'BLOQUEADO: base inesperada.' >&2; exit 1; }

# Resumo estrutural sem mostrar clientes nem movimentos.
echo 'Tabelas encontradas (1 = existe):'
for table in Extracto Contractos cis_cash_accounts cis_cash_links cis_cash_movements; do
  exists="$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table';")"
  echo "  $table = $exists"
  if [[ "$table" == 'Extracto' || "$table" == 'Contractos' ]]; then
    [[ "$exists" == 1 ]] || { echo "BLOQUEADO: tabela obrigatoria $table em falta." >&2; exit 1; }
  else
    [[ "$exists" == 0 ]] || { echo "BLOQUEADO: estrutura de Caixa ja existe ($table). Nao executar migracao nova." >&2; exit 1; }
  fi
done
engine="$(sql "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto';")"
echo "Extracto.ENGINE = $engine"
[[ "$engine" == 'InnoDB' ]] || { echo 'BLOQUEADO: Extracto nao e InnoDB.' >&2; exit 1; }
echo 'Colunas do Extracto:'
for field in Nr "Tipo de Movimento" Valor "Descrição" "Data da Operação" Contracto Parcela Cliente usuario; do
  count="$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='$field';")"
  printf '  %s = %s\n' "$field" "$count"
  [[ "$count" == 1 ]] || { echo "BLOQUEADO: campo obrigatorio $field em falta." >&2; exit 1; }
done
account_field="$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id';")"
echo "Extracto.account_id = $account_field"
[[ "$account_field" == 0 ]] || { echo 'BLOQUEADO: Extracto.account_id ja existe.' >&2; exit 1; }
nr_details="$(sql "SELECT CONCAT(DATA_TYPE,'|',COLUMN_TYPE,'|',EXTRA,'|',IS_NULLABLE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='Nr';")"
echo "Extracto.Nr = $nr_details"
case "$nr_details" in
  int\|*|bigint\|*) ;;
  *) echo 'BLOQUEADO: Nr nao e INT nem BIGINT.' >&2; exit 1 ;;
esac
pk="$(sql "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND CONSTRAINT_NAME='PRIMARY' AND COLUMN_NAME='Nr';")"
echo "Extracto.Nr como PRIMARY KEY = $pk"
[[ "$pk" == 1 ]] || { echo 'BLOQUEADO: Extracto.Nr nao e chave primaria.' >&2; exit 1; }
# Registos financeiros preexistentes nao serao classificados por conta.
count="$(sql 'SELECT COUNT(*) FROM `Extracto`;')"
echo "Movimentos existentes no Extracto (sem alterar): $count"
# Detectar possivel tentativa de migracao ja registada, sem confiar so nela.
migration_table="$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='migrations';")"
if [[ "$migration_table" == 1 ]]; then
  already="$(sql "SELECT COUNT(*) FROM migrations WHERE migration='2026_11_16_000030_create_cis_cash_tables';")"
  echo "Migração Caixa marcada como executada = $already"
  [[ "$already" == 0 ]] || { echo 'BLOQUEADO: migracao ja registada; investigar antes de qualquer comando.' >&2; exit 1; }
else
  echo 'BLOQUEADO: tabela Laravel migrations inexistente.' >&2
  exit 1
fi
echo 'PRE-VOO ESTRUTURAL OK. Nenhuma alteracao efectuada.'
echo 'ATENCAO: OK nao autoriza migracao em producao. Requer backup verificavel, teste de RESTAURO em copia, revisao do VBA/escritores, janela de manutencao e aprovacao humana.'
