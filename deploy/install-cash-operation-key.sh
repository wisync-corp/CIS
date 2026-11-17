#!/usr/bin/env bash
# Adicionar SOMENTE Extracto.operation_key UNIQUE NULLABLE.
# Exige ensaio isolado aprovado + backup + manutencao sem outros escritores.
# Nunca activa movimentos; nunca executa ALTER em caso de esquema inesperado.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
command -v flock >/dev/null || exit 1
command -v sha256sum >/dev/null || exit 1
sudo -v
exec 9>/tmp/cis-cash-operation-key.lock
flock -n 9 || { echo 'BLOQUEADO: outra instalação de operação em curso.' >&2; exit 1; }
COMPOSE=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
MYSQL=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
migration='2026_11_17_000050_add_operation_key_to_extracto'
file="database/migrations/$migration.php"
sql() {
 printf '%s\n' "$1" | "${MYSQL[@]}" exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'
}
check() {
 local name="$1" query="$2" expected="$3" actual
 actual="$(sql "$query")"
 [[ "$actual" == "$expected" ]] || { echo "BLOQUEADO: $name (encontrado $actual; esperado $expected)." >&2; exit 1; }
 echo "OK: $name"
}
preflight() {
 check 'base cis' 'SELECT DATABASE();' cis
 check 'cis_cash_accounts' "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts';" 1
 check 'Extracto.account_id nullable' "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id' AND IS_NULLABLE='YES';" 1
 check 'operation_key ainda ausente' "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='operation_key';" 0
 check 'indice ainda ausente' "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND INDEX_NAME='cis_extracto_operation_key_uq';" 0
 check 'sem tabelas paralelas' "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('cis_cash_links','cis_cash_movements');" 0
 check 'migração ainda não executada' "SELECT COUNT(*) FROM migrations WHERE migration='$migration';" 0
 check 'Extracto InnoDB' "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND UPPER(ENGINE)='INNODB';" 1
}
echo '=== CAPTA: MIGRACAO ADITIVA DE IDEMPOTENCIA ==='
echo 'Nenhuma cobrança, desembolso ou transferência será activada.'
if grep -Eq '^CIS_(CASH_WRITES|COLLECTION_WRITES)_APPROVED=true$' deploy/.env; then
 echo 'BLOQUEADO: escritas financeiras habilitadas; revisar antes da migração.' >&2; exit 1
fi
preflight
before="$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0),COALESCE(SUM(`Nr`),0) FROM `Extracto`;')"
contracts="$(sql 'SELECT COUNT(*) FROM Contractos;')"
accounts="$(sql 'SELECT COUNT(*),COALESCE(SUM(opening_balance),0) FROM cis_cash_accounts;')"
associated="$(sql 'SELECT COUNT(*) FROM Extracto WHERE account_id IS NOT NULL;')"
echo "Referências antes: Extracto=$before | Contractos=$contracts | contas=$accounts | associado=$associated"
echo 'A construir a imagem de API actual e comparar exactamente a migração...'
"${COMPOSE[@]}" build api-preview
host_sha="$(sha256sum "backend/$file" | awk '{print $1}')"
image_sha="$("${COMPOSE[@]}" run --rm --no-deps --entrypoint sha256sum api-preview "/srv/cis/$file" | awk '{print $1}')"
[[ "$host_sha" == "$image_sha" ]] || { echo 'BLOQUEADO: imagem API contém outra versão da migração.' >&2; exit 1; }
echo 'O ensaio isolado da versão actual deve ter sido aprovado sobre backup recente.'
echo 'Esta alteração é MySQL DDL: numa falha parcial NÃO repetir automaticamente nem fazer rollback.'
read -r -p 'Confirma ensaio, backup disponível e manutenção sem outros escritores? Digite ADICIONAR-OPERATION-KEY: ' answer
[[ "$answer" == 'ADICIONAR-OPERATION-KEY' ]] || { echo 'Cancelado, sem alterações.'; exit 0; }
echo 'A efectuar backup privado IMEDIATAMENTE antes da alteração...'
bash deploy/backup-before-auth.sh
stopped=false
finish() {
 local rc=$?
 trap - EXIT
 if [[ "$stopped" == true ]]; then
  echo 'A reiniciar a API privada...'
  "${COMPOSE[@]}" up -d --no-deps api-preview || true
 fi
 if [[ "$rc" -ne 0 ]]; then
  echo 'ATENÇÃO: procedimento interrompido; não repetir antes de inspeccionar migrations, informação do esquema e backup.' >&2
 fi
 exit "$rc"
}
trap finish EXIT
echo 'A parar apenas a API privada durante a alteração...'
"${COMPOSE[@]}" stop api-preview
stopped=true
preflight
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0),COALESCE(SUM(`Nr`),0) FROM `Extracto`;')" == "$before" ]] || { echo 'Extracto mudou; abortado.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*) FROM Contractos;')" == "$contracts" ]] || { echo 'Contractos mudou; abortado.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(opening_balance),0) FROM cis_cash_accounts;')" == "$accounts" ]] || { echo 'Contas mudaram; abortado.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*) FROM Extracto WHERE account_id IS NOT NULL;')" == "$associated" ]] || { echo 'Atribuições mudaram; abortado.' >&2; exit 1; }
echo 'A executar SOMENTE a migração aditiva validada...'
"${COMPOSE[@]}" run --rm --no-deps api-preview php artisan migrate --path="$file" --force
check 'operation_key VARCHAR(120) nullable' "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='operation_key' AND IS_NULLABLE='YES' AND CHARACTER_MAXIMUM_LENGTH=120;" 1
check 'UNIQUE operation key' "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND INDEX_NAME='cis_extracto_operation_key_uq' AND NON_UNIQUE=0;" 1
check 'migração registada' "SELECT COUNT(*) FROM migrations WHERE migration='$migration';" 1
check 'nenhum movimento antigo recebeu chave' 'SELECT COUNT(*) FROM Extracto WHERE operation_key IS NOT NULL;' 0
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor`),0),COALESCE(SUM(`Nr`),0) FROM `Extracto`;')" == "$before" ]] || { echo 'DIVERGÊNCIA financeira após migração.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*) FROM Contractos;')" == "$contracts" ]] || { echo 'DIVERGÊNCIA em contratos.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(opening_balance),0) FROM cis_cash_accounts;')" == "$accounts" ]] || { echo 'DIVERGÊNCIA em contas.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*) FROM Extracto WHERE account_id IS NOT NULL;')" == "$associated" ]] || { echo 'DIVERGÊNCIA em atribuições.' >&2; exit 1; }
echo 'OPERATION_KEY INSTALADA COM SUCESSO. Nenhuma operação financeira foi lançada ou activada.'
