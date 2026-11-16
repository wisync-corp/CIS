#!/usr/bin/env bash
# Corrigenda CAPTA Caixa: troca apenas estrutura ANTIGA VAZIA pela aprovada.
# Exige ensaio restaurado e confirmação textual. Não reexecuta a migration 000030.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env inexistente.' >&2; exit 1; }
command -v flock >/dev/null || { echo 'BLOQUEADO: flock em falta.' >&2; exit 1; }
exec 9>/tmp/cis-cash-repair.lock
flock -n 9 || { echo 'BLOQUEADO: reparação Caixa já está em curso.' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
MYSQL=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
migration_name="2026_11_17_000032_repair_legacy_cash_schema_to_direct_accounts"
migration_path="database/migrations/$migration_name.php"
sql() {
  printf '%s\n' "$1" | "${MYSQL[@]}" exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'
}
assert_eq() {
  local label="$1" query="$2" expected="$3" actual
  actual="$(sql "$query")"
  if [[ "$actual" != "$expected" ]]; then
    echo "BLOQUEADO: $label ($actual; esperado $expected)." >&2
    exit 1
  fi
  echo "OK: $label = $actual"
}
preflight() {
  [[ "$(sql 'SELECT DATABASE();')" == cis ]] || { echo 'BLOQUEADO: esquema diferente de cis.' >&2; exit 1; }
  assert_eq 'tabela cis_cash_accounts' "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts';" 1
  assert_eq 'tabela cis_cash_movements antiga' "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_movements';" 1
  assert_eq 'tabela cis_cash_links' "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_links';" 0
  assert_eq 'Extracto.account_id ainda ausente' "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id';" 0
  assert_eq 'opening_extracto_nr ainda ausente' "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts' AND COLUMN_NAME='opening_extracto_nr';" 0
  assert_eq 'contas da Caixa vazias' 'SELECT COUNT(*) FROM cis_cash_accounts;' 0
  assert_eq 'movimentos da Caixa antiga vazios' 'SELECT COUNT(*) FROM cis_cash_movements;' 0
  assert_eq 'migração anterior registada' "SELECT COUNT(*) FROM migrations WHERE migration='2026_11_16_000030_create_cis_cash_tables';" 1
  assert_eq 'migração correctiva ainda não registada' "SELECT COUNT(*) FROM migrations WHERE migration='$migration_name';" 0
}
echo '=== CORRECCAO CONTROLADA DA CAIXA ==='
echo 'A verificar estado antigo exacto (somente leitura)...'
preflight
before="$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0) FROM `Extracto`;')"
contracts="$(sql 'SELECT COUNT(*) FROM `Contractos`;')"
echo "Extracto antes: $before; Contractos: $contracts"

echo 'A reconstruir a imagem API antes da manutenção (evita executar migração antiga)...'
"${COMPOSE[@]}" build api-preview
host_sha="$(sha256sum "backend/$migration_path" | awk '{print $1}')"
image_sha="$("${COMPOSE[@]}" run --rm --no-deps --entrypoint sha256sum api-preview "/srv/cis/$migration_path" | awk '{print $1}')"
[[ "$host_sha" == "$image_sha" ]] || {
  echo "BLOQUEADO: a imagem API contém código de migração diferente do GitHub local." >&2
  exit 1
}
echo "OK: migração correctiva dentro da imagem corresponde ao ficheiro actual ($host_sha)."

echo 'A criar NOVO backup imediatamente antes da correcção...'
bash deploy/backup-before-auth.sh
echo 'Somente continuar se o último ensaio isolado tiver resultado APROVADO.'
echo 'Este procedimento irá parar temporariamente a API, criar Extracto.account_id,'
echo 'acrescentar opening_extracto_nr e remover a tabela ANTIGA VAZIA cis_cash_movements.'
read -r -p 'Autoriza a correcção real, numa janela sem operações, com backup recuperável? Digite CORRIGIR-CAIXA: ' approval
if [[ "$approval" != 'CORRIGIR-CAIXA' ]]; then
  echo 'Cancelado sem modificação da base.'
  exit 0
fi

stopped=false
on_exit() {
  local code=$?
  trap - EXIT
  if [[ "$stopped" == true ]]; then
    echo 'A reiniciar a API privada...'
    "${COMPOSE[@]}" up -d --no-deps api-preview || true
  fi
  if [[ "$code" -ne 0 ]]; then
    echo 'CORRECCAO INCOMPLETA/INCONSISTENTE. Não repetir nem fazer rollback.' >&2
    echo 'MySQL DDL não é integralmente transaccional: inspeccionar o esquema e o backup antes de novas alterações.' >&2
  fi
  exit "$code"
}
trap on_exit EXIT
echo 'A parar a API durante a alteração...'
"${COMPOSE[@]}" stop api-preview
stopped=true
echo 'A confirmar que a base continua exactamente no estado esperado...'
preflight
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0) FROM `Extracto`;')" == "$before" ]] || { echo 'BLOQUEADO: Extracto alterado desde o backup.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*) FROM `Contractos`;')" == "$contracts" ]] || { echo 'BLOQUEADO: Contractos alterado desde o backup.' >&2; exit 1; }

echo 'A executar exclusivamente a migration correctiva na imagem validada...'
"${COMPOSE[@]}" run --rm --no-deps api-preview php artisan migrate \
  --path="$migration_path" --force

echo 'A verificar resultados estruturais e financeiros...'
assert_eq 'cis_cash_accounts preservada' "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts';" 1
assert_eq 'cis_cash_movements antiga ausente' "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_movements';" 0
assert_eq 'cis_cash_links ausente' "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_links';" 0
assert_eq 'Extracto.account_id nullable' "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id' AND IS_NULLABLE='YES';" 1
assert_eq 'opening_extracto_nr disponível' "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts' AND COLUMN_NAME='opening_extracto_nr';" 1
assert_eq 'contas continuam vazias' 'SELECT COUNT(*) FROM cis_cash_accounts;' 0
assert_eq 'nenhum movimento histórico recebeu conta' 'SELECT COUNT(*) FROM `Extracto` WHERE account_id IS NOT NULL;' 0
assert_eq 'migração correctiva registada' "SELECT COUNT(*) FROM migrations WHERE migration='$migration_name';" 1
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0) FROM `Extracto`;')" == "$before" ]] || { echo 'BLOQUEADO: Extracto financeiro alterado.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*) FROM `Contractos`;')" == "$contracts" ]] || { echo 'BLOQUEADO: Contractos alterado.' >&2; exit 1; }
echo 'CORRECCAO DA CAIXA: CONCLUIDA; dados financeiros preservados.'
echo 'Lançamentos, desembolsos, cobranças e transferências da Caixa continuam bloqueados.'
