#!/usr/bin/env bash
# CAPTA: ELIMINAR do Extracto os movimentos lançados numa conta da Caixa
# (decisão do proprietário: movimentos reais mas errados). Excepção à regra de
# imutabilidade — o caminho normal é o estorno.
#
#   bash deploy/delete-cash-account-movements.sh BIM-001              # analisar e eliminar
#   bash deploy/delete-cash-account-movements.sh --restaurar FICHEIRO # repor a partir da cópia
#
# Mantém a conta, o saldo inicial e o marcador. Bloqueia se algum movimento:
#  - estiver ligado a contrato/prestação (desembolso ou cobrança);
#  - for perna de transferência ou estorno com outra perna noutra conta;
#  - estiver coberto por um fecho diário já registado.
# Antes de apagar: backup completo + cópia exacta (INSERTs) das linhas apagadas.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-cash-delete-movements.lock
flock -n 9 || { echo 'BLOQUEADO: operação concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql(){ printf '%s\n' "$1" | timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
dir="$HOME/cis-private/cash-verification/deleted-movements"
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || exit 1

if [[ "${1:-}" == --restaurar ]]; then
  f="${2:-}"; [[ -s "$f" && "$f" == "$dir"/* ]] || { echo "Indique um ficheiro em $dir" >&2; exit 1; }
  echo "Repor as linhas guardadas em $f (com os Nr originais)."
  read -r -p 'Digite RESTAURAR-MOVIMENTOS: ' a; [[ "$a" == RESTAURAR-MOVIMENTOS ]] || exit 0
  bash deploy/backup-before-auth.sh
  timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 "$MYSQL_DATABASE"' < "$f"
  echo 'MOVIMENTOS RESTAURADOS.'; exit 0
fi

CODE="${1:-}"
[[ "$CODE" =~ ^[A-Za-z0-9._-]{1,40}$ ]] || { echo 'Uso: bash deploy/delete-cash-account-movements.sh <CÓDIGO-DA-CONTA>' >&2; exit 1; }
IFS=$'\t' read -r ACC NAME OPENING MARK < <(sql "SELECT id,name,opening_balance,COALESCE(opening_extracto_nr,'NULL') FROM cis_cash_accounts WHERE code='$CODE';") || true
[[ "${ACC:-}" =~ ^[0-9]+$ ]] || { echo "BLOQUEADO: conta $CODE não encontrada." >&2; exit 1; }
echo "=== CAPTA | ELIMINAR MOVIMENTOS DA CONTA $CODE · $NAME (id $ACC) ==="
IFS=$'\t' read -r N SUMNR < <(sql "SELECT COUNT(*),COALESCE(SUM(Nr),0) FROM Extracto WHERE account_id=$ACC;")
[[ "$N" -ge 1 ]] || { echo 'A conta não tem movimentos. Nada a eliminar.'; exit 0; }
echo "Movimentos encontrados: $N"
sql "SELECT CONCAT('Nr ',Nr,' | ',\`Data da Operação\`,' | ',\`Tipo de Movimento\`,' | ',Valor,' MT | ',COALESCE(\`Descrição\`,''),' | chave ',COALESCE(operation_key,'-')) FROM Extracto WHERE account_id=$ACC ORDER BY Nr;"

contract="$(sql "SELECT COUNT(*) FROM Extracto WHERE account_id=$ACC AND ((Contracto IS NOT NULL AND TRIM(CAST(Contracto AS CHAR))<>'' AND CAST(Contracto AS CHAR)<>'0') OR COALESCE(Parcela,0)<>0);")"
[[ "$contract" == 0 ]] || { echo "BLOQUEADO: $contract movimento(s) ligados a contratos/prestações. Eliminar quebraria desembolsos ou cobranças." >&2; exit 1; }
grp="SUBSTRING(operation_key,1,CHAR_LENGTH(operation_key)-CHAR_LENGTH(SUBSTRING_INDEX(operation_key,':',-1))-1)"
paired="$(sql "SELECT COUNT(*) FROM Extracto o WHERE (o.account_id IS NULL OR o.account_id<>$ACC) AND o.operation_key IS NOT NULL AND ${grp//operation_key/o.operation_key} IN (SELECT ${grp} FROM Extracto WHERE account_id=$ACC AND operation_key IS NOT NULL);")"
[[ "$paired" == 0 ]] || { echo "BLOQUEADO: $paired perna(s) de transferência/estorno noutra conta. Eliminar só um lado desequilibraria a outra conta." >&2; exit 1; }
if [[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_daily_closures';")" == 1 ]]; then
  [[ "$(sql "SELECT COUNT(*) FROM cis_cash_daily_closures WHERE account_id=$ACC;")" == 0 ]] || {
    echo 'BLOQUEADO: já existem fechos diários desta conta; são fotografias imutáveis.' >&2; exit 1; }
fi
echo
echo "Depois da eliminação, o saldo da conta volta ao saldo inicial validado: $OPENING MT."
echo 'A conta, o saldo inicial e o marcador de abertura mantêm-se. Esta eliminação é DEFINITIVA na base'
echo '(fica apenas o backup e a cópia das linhas para eventual reposição).'
read -r -p "Confirma eliminar $N movimento(s)? Digite APAGAR-MOVIMENTOS-$CODE: " a
[[ "$a" == "APAGAR-MOVIMENTOS-$CODE" ]] || { echo 'Cancelado. Nada foi alterado.'; exit 0; }
bash deploy/backup-before-auth.sh
mkdir -p "$dir"
copy="$dir/$CODE-$(date +%Y%m%d%H%M%S).sql"
timeout -k 5 60 "${DB[@]}" exec -T mysql sh -c "MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" exec mysqldump -uroot --default-character-set=utf8mb4 --no-create-info --skip-triggers --complete-insert --skip-add-locks --where='account_id=$ACC' \"\$MYSQL_DATABASE\" Extracto" </dev/null > "$copy"
chmod 600 "$copy"
[[ "$(grep -c 'INSERT INTO' "$copy" || true)" -ge 1 ]] || { echo 'BLOQUEADO: cópia das linhas falhou; nada apagado.' >&2; exit 1; }
sql "CREATE TEMPORARY TABLE chk(ok TINYINT NOT NULL, CHECK (ok=1));
START TRANSACTION;
SELECT id FROM cis_cash_accounts WHERE id=$ACC FOR UPDATE;
INSERT INTO chk SELECT (COUNT(*)=$N AND COALESCE(SUM(Nr),0)=$SUMNR) FROM Extracto WHERE account_id=$ACC;
DELETE FROM Extracto WHERE account_id=$ACC;
INSERT INTO chk SELECT (ROW_COUNT()=$N);
COMMIT;" >/dev/null || { echo 'FALHOU: transacção revertida (houve movimento novo entretanto?). Nada apagado.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM Extracto WHERE account_id=$ACC;")" == 0 ]] || { echo 'ATENÇÃO: ainda há linhas da conta.' >&2; exit 1; }
echo "MOVIMENTOS ELIMINADOS: $N. Saldo da conta = saldo inicial ($OPENING MT)."
echo "Cópia para reposição: $copy"
echo "Para repor: bash deploy/delete-cash-account-movements.sh --restaurar $copy"
