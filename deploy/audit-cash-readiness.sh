#!/usr/bin/env bash
# Caixa Geral: verificacao de prontidao por camadas. Apenas SELECT.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'Sem deploy/.env privado.' >&2; exit 1; }
DBCOMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql(){
 printf '%s\n' "$1" | "${DBCOMPOSE[@]}" exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'
}
flag(){
 if grep -qx "$1=true" deploy/.env; then echo "$1 = ACTIVA"; else echo "$1 = DESACTIVADA"; fi
}
echo '=== CAPTA / CAIXA GERAL — DIAGNOSTICO SO LEITURA ==='
db="$(sql 'SELECT DATABASE();')"
echo "Base: $db"
[[ "$db" == cis ]] || { echo 'BLOQUEADO: banco inesperado.' >&2; exit 1; }
for t in cis_cash_accounts Extracto; do
 n="$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t';")"
 [[ "$n" == 1 ]] || { echo "BLOQUEADO: tabela $t ausente." >&2; exit 1; }
done
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id';")" == 1 ]] || { echo 'BLOQUEADO: Extracto.account_id nao existe.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('cis_cash_movements','cis_cash_links');")" == 0 ]] || { echo 'BLOQUEADO: schema de movimentos duplicado.' >&2; exit 1; }
echo 'Esquema: APROVADO (uma tabela de contas + Extracto.account_id)'
echo "Contas por estado (apenas contagens):"
sql 'SELECT status,COUNT(*) FROM cis_cash_accounts GROUP BY status ORDER BY status;'
echo "Contas activas sem marcador de abertura:"
invalid="$(sql "SELECT COUNT(*) FROM cis_cash_accounts WHERE status='activa' AND (opening_balance IS NULL OR cut_at IS NULL OR opening_extracto_nr IS NULL OR validated_by IS NULL OR validated_at IS NULL);")"
echo "$invalid"
echo "Contas activas com aprovador igual ao cadastrador:"
same_user="$(sql "SELECT COUNT(*) FROM cis_cash_accounts WHERE status='activa' AND created_by=validated_by;")"
echo "$same_user"
echo "Movimentos Extracto historicos nao associados:"
sql "SELECT COUNT(*) FROM Extracto WHERE account_id IS NULL;"
echo "Movimentos Extracto associados:"
sql "SELECT COUNT(*) FROM Extracto WHERE account_id IS NOT NULL;"
echo "Movimentos com account_id sem conta correspondente:"
orphan="$(sql 'SELECT COUNT(*) FROM Extracto e LEFT JOIN cis_cash_accounts a ON e.account_id=a.id WHERE e.account_id IS NOT NULL AND a.id IS NULL;')"
echo "$orphan"
if [[ "$invalid" != 0 || "$same_user" != 0 || "$orphan" != 0 ]]; then
 echo 'DIAGNOSTICO REPROVADO: existem inconsistencias financeiras; NAO activar movimentos.' >&2
 exit 1
fi
echo 'INTEGRIDADE E SEGREGACAO BASICA: APROVADAS.'
echo 'Flags do modulo (nao exibem palavras-passe):'
for k in CIS_CASH_ACCOUNT_SETUP_APPROVED CIS_CASH_OPENING_SETUP_APPROVED CIS_CASH_OPENING_VALIDATE_APPROVED CIS_CASH_WRITES_APPROVED CIS_COLLECTION_WRITES_APPROVED; do flag "$k"; done
echo 'AVISO: este diagnostico nao prova saldo bancario ou reconciliação externa.'
echo 'Nenhuma alteracao executada na base.'
