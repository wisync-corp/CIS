#!/usr/bin/env bash
# Instalação controlada de cis_cash_accounts + Extracto.account_id.
# EXIGE confirmação explícita no terminal. Não atribui contas nem cria operações financeiras.
# A migração MySQL usa o serviço Laravel com credenciais configuradas.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env em falta.' >&2; exit 1; }
command -v flock >/dev/null || { echo 'BLOQUEADO: flock em falta.' >&2; exit 1; }
exec 9>/tmp/cis-cash-install.lock
flock -n 9 || { echo 'BLOQUEADO: outra instalação Caixa já está em curso.' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
MYSQL=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql() {
  printf '%s\n' "$1" | "${MYSQL[@]}" exec -T mysql sh -c   'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'
}

echo '=== INSTALAÇÃO CONTROLADA DA CAIXA ==='
echo 'Pré-voo de leitura:'
bash deploy/preflight-cash-direct-account.sh

# Guardar instantâneo apenas de métricas, não de dados privados.
before="$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0) FROM `Extracto`;')"
contracts="$(sql 'SELECT COUNT(*) FROM `Contractos`;')"
echo "Movimentos e soma anteriores: $before"
echo "Registos de Contractos anteriores: $contracts"

echo 'A criar backup NOVO imediatamente antes da manutenção...'
bash deploy/backup-before-auth.sh
echo 'IMPORTANTE: suspender operações de CAPTA durante a migração.'
echo 'A API privada será parada para manutenção (nenhum pedido de desembolso poderá ser atendido).'
read -r -p 'Confirmou ensaio isolado aprovado, backup restaurável, manutenção e autorização para modificar a base real? Digite MIGRAR-CAIXA: ' approval
[[ "$approval" == 'MIGRAR-CAIXA' ]] || { echo 'Cancelado: sem alterações na base.'; exit 0; }

stopped=false
restore_api() {
  local status=$?
  trap - EXIT
  if [[ "$stopped" == true ]]; then
    echo 'A restaurar serviço API da pré-visualização privada...'
    "${COMPOSE[@]}" up -d --no-deps api-preview || true
  fi
  if [[ "$status" -ne 0 ]]; then
    echo 'ATENÇÃO: a migração falhou ou a verificação não passou.' >&2
    echo 'DDL MySQL não garante rollback automático: NÃO executar outra migração; inspeccionar o esquema, as migrações e o backup.' >&2
  fi
  exit "$status"
}
trap restore_api EXIT

# Evita pedidos novos à API durante a alteração DDL.
"${COMPOSE[@]}" stop api-preview
stopped=true

# Confirma ausência de escritas durante a janela.
now="$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0) FROM `Extracto`;')"
now_contracts="$(sql 'SELECT COUNT(*) FROM `Contractos`;')"
[[ "$now" == "$before" && "$now_contracts" == "$contracts" ]] || {
 echo 'BLOQUEADO: houve movimentos entre o backup/pre-voo e a paragem do API.' >&2
 exit 1
}

echo 'Executar SOMENTE a migração Caixa via Laravel; sem outras migrations...'
"${COMPOSE[@]}" run --rm --no-deps api-preview  php artisan migrate --path=database/migrations/2026_11_16_000030_create_cis_cash_tables.php --force

echo 'Verificar estrutura, históricos e migração registada...'
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts';")" == 1 ]] || exit 1
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id' AND IS_NULLABLE='YES';")" == 1 ]] || exit 1
[[ "$(sql 'SELECT COUNT(*) FROM `Extracto` WHERE account_id IS NOT NULL;')" == 0 ]] || {
 echo 'BLOQUEADO: movimentos legados foram associados inesperadamente.' >&2; exit 1;
}
[[ "$(sql 'SELECT COUNT(*) FROM `cis_cash_accounts`;')" == 0 ]] || { echo 'BLOQUEADO: existem contas inesperadas.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0) FROM `Extracto`;')" == "$before" ]] || { echo 'BLOQUEADO: o Extracto foi alterado.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*) FROM `Contractos`;')" == "$contracts" ]] || { echo 'BLOQUEADO: Contractos foi alterado.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM `migrations` WHERE migration='2026_11_16_000030_create_cis_cash_tables';")" == 1 ]] || {
 echo 'BLOQUEADO: migração não ficou registada.' >&2; exit 1;
}
echo 'MIGRAÇÃO DA CAIXA CONCLUÍDA: estrutura criada e histórico preservado.'
echo 'Registos de movimentos, transferências e desembolsos NOVOS continuam desactivados.'
