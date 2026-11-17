#!/usr/bin/env bash
# PREPARAR (não activar) escritor real de Caixa para Extracto.
# Requer ensaio real de MySQL isolado aprovado e manutenção supervisionada.
# Sem INSERT financeiro neste script; flags permanecem DESACTIVADAS.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
for cmd in flock openssl sha256sum; do command -v "$cmd" >/dev/null || exit 1; done
sudo -v
exec 9>/tmp/cis-cash-ledger-writer.lock
flock -n 9 || { echo 'BLOQUEADO: preparação de escritor em curso.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){
 printf '%s\n' "$1" | timeout 35 "${DB[@]}" exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'
}
check(){
 local value
 value="$(sql "$1")"
 [[ "$value" == "$2" ]] || { echo "BLOQUEADO: $3 (actual $value)." >&2; exit 1; }
}
echo '=== CAIXA: PREPARAR CONEXAO RESTRITA (SEM ACTIVAR ESCRITAS) ==='
check 'SELECT DATABASE();' cis 'Base inesperada'
check "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND ENGINE='InnoDB';" 1 'Extracto deve ser InnoDB'
check "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts' AND ENGINE='InnoDB';" 1 'Contas devem ser InnoDB'
check "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id';" 1 'account_id em falta'
check "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='operation_key' AND IS_NULLABLE='YES';" 1 'operation_key não instalada'
check "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND INDEX_NAME='cis_extracto_operation_key_uq' AND NON_UNIQUE=0;" 1 'indice idempotente ausente'
check "SELECT COUNT(*) FROM mysql.user WHERE User='cis_cash_ledger';" 0 'escritor já criado; investigar, não repetir'
if grep -Eq '^(CIS_CASH_WRITES_APPROVED|CIS_CASH_LEDGER_POSTING_APPROVED)=true$' deploy/.env; then
 echo 'BLOQUEADO: as flags de escrita já estão activadas.' >&2; exit 1
fi
if grep -q '^CASH_LEDGER_DB_PASSWORD=' deploy/.env; then
 echo 'BLOQUEADO: credencial presente. Verificar manualmente antes de repetir.' >&2; exit 1
fi
echo 'Esta fase concede SOMENTE SELECT contas, UPDATE(updated_at) contas (FOR UPDATE requer UPDATE), SELECT/INSERT Extracto.'
echo 'Sem UPDATE/DELETE Extracto; sem privilégios de contratos.'
echo 'A API será reconstruída com a ligação preparada; movimentos permanecerão DESACTIVADOS.'
read -r -p 'Ensaio MySQL real isolado aprovado e comprovado? Digite PREPARAR-ESCRITOR-CAIXA: ' approval
[[ "$approval" == 'PREPARAR-ESCRITOR-CAIXA' ]] || { echo 'Cancelado.'; exit 0; }
bash deploy/backup-before-auth.sh
password="$(openssl rand -hex 32)"
sql "CREATE USER 'cis_cash_ledger'@'%' IDENTIFIED BY '$password';
GRANT SELECT ON \`cis\`.\`cis_cash_accounts\` TO 'cis_cash_ledger'@'%';
GRANT UPDATE (updated_at) ON \`cis\`.\`cis_cash_accounts\` TO 'cis_cash_ledger'@'%';
GRANT SELECT, INSERT ON \`cis\`.\`Extracto\` TO 'cis_cash_ledger'@'%';"
{
 printf '\nCASH_LEDGER_DB_USERNAME=cis_cash_ledger\n'
 printf 'CASH_LEDGER_DB_PASSWORD=%s\n' "$password"
 printf 'CIS_CASH_WRITES_APPROVED=false\n'
 printf 'CIS_CASH_LEDGER_POSTING_APPROVED=false\n'
} >> deploy/.env
chmod 600 deploy/.env
echo 'A reconstruir API sem activar os movimentos...'
"${APP[@]}" up -d --no-deps --build --force-recreate api-preview
echo 'Escritor criado. O código continua protegido por DUAS flags falsas.'
echo 'Se houver erro NÃO repetir: verificar GRANTS, deploy/.env e container de API.'
