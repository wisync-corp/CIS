#!/usr/bin/env bash
# Reverte assign-legacy-extracto-to-numerario.sh: devolve account_id=NULL às linhas
# históricas atribuídas e repõe marcador e data de corte originais.
# Movimentos lançados pelo CIS (operation_key preenchida) não são tocados.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || exit 1
sudo -v
exec 9>/tmp/cis-legacy-numerario.lock
flock -n 9 || { echo 'BLOQUEADO: operação concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql(){ printf '%s\n' "$1" | timeout 120 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
state="$HOME/cis-private/cash-verification/legacy-numerario-assignment.state"
[[ -s "$state" ]] || { echo 'Não há atribuição registada para reverter.' >&2; exit 1; }
# shellcheck disable=SC1090
source "$state"
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || exit 1
if [[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_daily_closures';")" == 1 ]]; then
  [[ "$(sql "SELECT COUNT(*) FROM cis_cash_daily_closures WHERE account_id=$ACC;")" == 0 ]] || {
    echo 'BLOQUEADO: já há fechos desta conta registados sobre o histórico; reversão exige decisão manual.' >&2; exit 1; }
fi
echo "Reverter $N linhas (Nr até $MAXNR) e repor marcador $OLDNR / corte $OLDCUT."
read -r -p 'Digite REVERTER-HISTORICO-NUMERARIO: ' answer
[[ "$answer" == 'REVERTER-HISTORICO-NUMERARIO' ]] || { echo 'Cancelado.'; exit 0; }
bash deploy/backup-before-auth.sh
sql "CREATE TEMPORARY TABLE chk(ok TINYINT NOT NULL, CHECK (ok=1));
START TRANSACTION;
SELECT id FROM cis_cash_accounts WHERE id=$ACC FOR UPDATE;
INSERT INTO chk SELECT (COUNT(*)=$N AND COALESCE(SUM(Nr),0)=$SUMNR) FROM Extracto WHERE account_id=$ACC AND Nr<=$MAXNR AND operation_key IS NULL;
UPDATE Extracto SET account_id=NULL WHERE account_id=$ACC AND Nr<=$MAXNR AND operation_key IS NULL;
INSERT INTO chk SELECT (ROW_COUNT()=$N);
UPDATE cis_cash_accounts SET opening_extracto_nr=$OLDNR, cut_at='$OLDCUT', updated_at=NOW() WHERE id=$ACC AND opening_extracto_nr=0;
INSERT INTO chk SELECT (ROW_COUNT()=1);
COMMIT;" >/dev/null || { echo 'FALHOU: ROLLBACK, nada alterado.' >&2; exit 1; }
mv "$state" "$state.reverted-$(date +%Y%m%d%H%M%S)"
echo 'REVERSÃO CONCLUÍDA: histórico volta a não ter conta.'
