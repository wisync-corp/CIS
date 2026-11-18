#!/usr/bin/env bash
# CAPTA: ELIMINAR todas as transacções de um MUTUÁRIO DE TESTE.
#
#   bash deploy/delete-test-borrower.sh --procurar Teste        # encontrar o ID (só leitura)
#   bash deploy/delete-test-borrower.sh <ID_CLIENTE>            # analisar e eliminar
#   bash deploy/delete-test-borrower.sh --restaurar <PASTA>     # repor a partir da cópia
#
# Apaga: prestações (Contractos) do cliente e movimentos do Extracto desses contratos
# (desembolsos e pagamentos, incluindo os que estão em contas da Caixa).
# Mantém: o cadastro do cliente e o HISTÓRICO IMUTÁVEL (acrescenta um registo
# 'eliminacao_teste' com a fotografia de cada prestação apagada).
# Bloqueia se: contrato partilhado com outro cliente, fecho diário nos dias afectados,
# ou saldo de alguma conta ficar negativo.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-delete-test-borrower.lock
flock -n 9 || { echo 'BLOQUEADO: operação concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql(){ printf '%s\n' "$1" | timeout 120 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
dir="$HOME/cis-private/cash-verification/deleted-test-borrowers"
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || exit 1
has(){ [[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$1';")" == 1 ]]; }

if [[ "${1:-}" == --procurar ]]; then
  term="${2:-}"; [[ "$term" =~ ^[[:alnum:][:space:]._-]{2,40}$ ]] || { echo 'Indique parte do nome (2-40 letras/números).' >&2; exit 1; }
  echo "Clientes e contratos com \"$term\" no nome (só leitura):"
  sql "SELECT CONCAT('ID ',\`Nº de Cliente\`,' | ',\`Nome do Cliente\`,' | ',COUNT(DISTINCT \`Nr do Contracto\`),' contrato(s), ',COUNT(*),' prestação(ões)') FROM Contractos WHERE \`Nome do Cliente\` LIKE CONCAT('%','$term','%') GROUP BY \`Nº de Cliente\`,\`Nome do Cliente\` ORDER BY \`Nº de Cliente\`;"
  echo 'Depois: bash deploy/delete-test-borrower.sh <ID>'
  exit 0
fi

if [[ "${1:-}" == --restaurar ]]; then
  d="${2:-}"; [[ -d "$d" && "$d" == "$dir"/* && -s "$d/contractos.sql" ]] || { echo "Indique uma pasta em $dir" >&2; exit 1; }
  read -r -p 'Repor as prestações e movimentos guardados? Digite RESTAURAR-MUTUARIO: ' a
  [[ "$a" == RESTAURAR-MUTUARIO ]] || exit 0
  bash deploy/backup-before-auth.sh
  for f in contractos.sql extracto.sql; do
    [[ -s "$d/$f" ]] && timeout 120 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 "$MYSQL_DATABASE"' < "$d/$f"
  done
  echo 'MUTUÁRIO RESTAURADO (prestações e movimentos).'; exit 0
fi

ID="${1:-}"
[[ "$ID" =~ ^[1-9][0-9]{0,8}$ ]] || { echo 'Uso: bash deploy/delete-test-borrower.sh --procurar Teste | <ID_CLIENTE>' >&2; exit 1; }
NAME="$(sql "SELECT GROUP_CONCAT(DISTINCT \`Nome do Cliente\` SEPARATOR ' / ') FROM Contractos WHERE \`Nº de Cliente\`=$ID;")"
[[ -n "$NAME" && "$NAME" != NULL ]] || { echo "Não há contratos para o cliente ID $ID." >&2; exit 1; }
CONTRACTS="$(sql "SELECT GROUP_CONCAT(DISTINCT \`Nr do Contracto\` ORDER BY \`Nr do Contracto\` SEPARATOR ',') FROM Contractos WHERE \`Nº de Cliente\`=$ID;")"
for c in ${CONTRACTS//,/ }; do [[ "$c" =~ ^[A-Za-z0-9._/-]{1,40}$ ]] || { echo "BLOQUEADO: número de contrato inesperado ($c)." >&2; exit 1; }; done
IN="'${CONTRACTS//,/\',\'}'"
echo "=== CAPTA | ELIMINAR TRANSACÇÕES DO MUTUÁRIO ID $ID · $NAME ==="
echo 'Contratos e prestações:'
sql "SELECT CONCAT('  ',\`Nr do Contracto\`,' | ',COUNT(*),' prestação(ões) | ',GROUP_CONCAT(DISTINCT Estado),' | início ',MIN(\`Data de Inicio\`)) FROM Contractos WHERE \`Nº de Cliente\`=$ID GROUP BY \`Nr do Contracto\`;"
shared="$(sql "SELECT COUNT(*) FROM Contractos WHERE \`Nr do Contracto\` IN ($IN) AND \`Nº de Cliente\`<>$ID;")"
[[ "$shared" == 0 ]] || { echo "BLOQUEADO: $shared prestação(ões) destes contratos pertencem a outro cliente." >&2; exit 1; }
IFS=$'\t' read -r NC SUMC < <(sql "SELECT COUNT(*),COALESCE(SUM(Ordem),0) FROM Contractos WHERE \`Nº de Cliente\`=$ID;")
IFS=$'\t' read -r NE SUME < <(sql "SELECT COUNT(*),COALESCE(SUM(Nr),0) FROM Extracto WHERE Contracto IN ($IN);")
echo "Movimentos do Extracto ligados ($NE):"
sql "SELECT CONCAT('  Nr ',Nr,' | ',\`Data da Operação\`,' | ',\`Tipo de Movimento\`,' | ',Valor,' MT | conta ',COALESCE(account_id,'-'),' | ',LEFT(COALESCE(\`Descrição\`,''),60)) FROM Extracto WHERE Contracto IN ($IN) ORDER BY Nr;"

# Efeito nas contas da Caixa (mesma regra de saldo: abertura + movimentos acima do marcador).
if has cis_cash_accounts; then
  echo 'Saldos das contas afectadas (antes → depois):'
  sql "SELECT CONCAT('  ',a.code,': ',
     ROUND(a.opening_balance+COALESCE(SUM(CASE WHEN e.\`Tipo de Movimento\`='Entradas' THEN e.Valor ELSE -e.Valor END),0),2),' → ',
     ROUND(a.opening_balance+COALESCE(SUM(CASE WHEN e.Contracto IN ($IN) THEN 0 WHEN e.\`Tipo de Movimento\`='Entradas' THEN e.Valor ELSE -e.Valor END),0),2),' MT')
   FROM cis_cash_accounts a LEFT JOIN Extracto e ON e.account_id=a.id AND e.Nr>a.opening_extracto_nr
   WHERE a.id IN (SELECT DISTINCT account_id FROM Extracto WHERE Contracto IN ($IN) AND account_id IS NOT NULL)
   GROUP BY a.id,a.code,a.opening_balance;"
  neg="$(sql "SELECT COUNT(*) FROM (SELECT a.id, a.opening_balance+COALESCE(SUM(CASE WHEN e.Contracto IN ($IN) THEN 0 WHEN e.\`Tipo de Movimento\`='Entradas' THEN e.Valor ELSE -e.Valor END),0) AS depois
     FROM cis_cash_accounts a LEFT JOIN Extracto e ON e.account_id=a.id AND e.Nr>a.opening_extracto_nr
     WHERE a.id IN (SELECT DISTINCT account_id FROM Extracto WHERE Contracto IN ($IN) AND account_id IS NOT NULL)
     GROUP BY a.id,a.opening_balance) x WHERE depois < -0.004;")"
  [[ "$neg" == 0 ]] || { echo 'BLOQUEADO: alguma conta ficaria com saldo negativo (o dinheiro já foi usado noutras operações).' >&2; exit 1; }
fi
if has cis_cash_daily_closures; then
  closed="$(sql "SELECT COUNT(*) FROM cis_cash_daily_closures c JOIN Extracto e ON e.account_id=c.account_id AND c.closing_date>=e.\`Data da Operação\` WHERE e.Contracto IN ($IN);")"
  [[ "$closed" == 0 ]] || { echo 'BLOQUEADO: existem fechos diários nos dias/contas afectados (fotografias imutáveis).' >&2; exit 1; }
fi
HIST=0; has cis_contractos_historico && HIST=1
echo
echo "Serão APAGADAS $NC prestação(ões) e $NE movimento(s) do Extracto. O cadastro do cliente mantém-se."
[[ "$HIST" == 1 ]] && echo 'O histórico imutável mantém-se e recebe um registo "eliminacao_teste" por prestação apagada.'
read -r -p "Confirma? Digite APAGAR-MUTUARIO-$ID: " answer
[[ "$answer" == "APAGAR-MUTUARIO-$ID" ]] || { echo 'Cancelado. Nada foi alterado.'; exit 0; }

bash deploy/backup-before-auth.sh
out="$dir/cliente-$ID-$(date +%Y%m%d%H%M%S)"; mkdir -p "$out"
dump(){ timeout 120 "${DB[@]}" exec -T -e W="$2" mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --default-character-set=utf8mb4 --no-create-info --skip-triggers --complete-insert --skip-add-locks --single-transaction --skip-lock-tables --column-statistics=0 --where="$W" "$MYSQL_DATABASE" '"$1"; }
# Antes de escrever: mostrar sessões que possam bloquear (transacções abertas há >10 s).
blockers="$(sql "SELECT CONCAT('  sessão ',p.ID,' | ',p.USER,' | ',p.TIME,' s | ',COALESCE(p.STATE,''),' | ',LEFT(COALESCE(p.INFO,''),70)) FROM information_schema.PROCESSLIST p JOIN information_schema.INNODB_TRX t ON t.trx_mysql_thread_id=p.ID WHERE p.ID<>CONNECTION_ID() AND TIMESTAMPDIFF(SECOND,t.trx_started,NOW())>10;")"
if [[ -n "$blockers" ]]; then
  echo 'ATENÇÃO: há transacções abertas na base que podem bloquear a eliminação:' >&2; echo "$blockers" >&2
  echo 'Se forem de execuções antigas deste script, termine-as antes (ver instruções). Nada foi alterado.' >&2; exit 1
fi
echo 'A guardar cópia das prestações…'
dump Contractos "\`Nº de Cliente\`=$ID" > "$out/contractos.sql"
echo 'A guardar cópia dos movimentos…'
[[ "$NE" == 0 ]] || dump Extracto "Contracto IN ($IN)" > "$out/extracto.sql"
grep -q 'INSERT INTO' "$out/contractos.sql" || { echo 'BLOQUEADO: cópia das prestações falhou; nada apagado.' >&2; exit 1; }
chmod 600 "$out"/*.sql

KEY="delete:test:$ID:$(date +%s)"
histsql=""
if [[ "$HIST" == 1 ]]; then
  histsql="INSERT INTO cis_contractos_historico(contract_number,installment_ordem,installment_number,operation,before_snapshot,after_snapshot,reason,operation_key,performed_by,performed_at)
 SELECT \`Nr do Contracto\`,Ordem,\`Nº da Parcela\`,'eliminacao_teste',
  JSON_OBJECT('Ordem',Ordem,'Nr do Contracto',\`Nr do Contracto\`,'Nº da Parcela',\`Nº da Parcela\`,'Nome do Cliente',\`Nome do Cliente\`,
   'Nº de Cliente',\`Nº de Cliente\`,'Quantia Creditada',\`Quantia Creditada\`,'Capital',Capital,'Amortização',\`Amortização\`,
   'Valor de Juros',\`Valor de Juros\`,'Valor de Multa',\`Valor de Multa\`,'Valor a Pagar',\`Valor a Pagar\`,'Valor Pago',\`Valor Pago\`,
   'Data de Inicio',\`Data de Inicio\`,'Data de Cobrança',\`Data de Cobrança\`,'Data de Pagamento',\`Data de Pagamento\`,
   'Estado',Estado,'Estado do Contrato',\`Estado do Contrato\`,'Situação de Pagamento',\`Situação de Pagamento\`),
  NULL,'Eliminação de mutuário de teste (cópia em $out)','$KEY',0,NOW()
 FROM Contractos WHERE \`Nº de Cliente\`=$ID;
INSERT INTO chk SELECT (ROW_COUNT()=$NC);"
fi
echo 'A eliminar numa única transacção (máx. 20 s de espera por bloqueios)…'
if ! sql "SET SESSION lock_wait_timeout=20; SET SESSION innodb_lock_wait_timeout=20;
CREATE TEMPORARY TABLE chk(ok TINYINT NOT NULL, CHECK (ok=1));
START TRANSACTION;
INSERT INTO chk SELECT (COUNT(*)=$NC AND COALESCE(SUM(Ordem),0)=$SUMC) FROM Contractos WHERE \`Nº de Cliente\`=$ID;
INSERT INTO chk SELECT (COUNT(*)=$NE AND COALESCE(SUM(Nr),0)=$SUME) FROM Extracto WHERE Contracto IN ($IN);
$histsql
DELETE FROM Extracto WHERE Contracto IN ($IN);
INSERT INTO chk SELECT (ROW_COUNT()=$NE);
DELETE FROM Contractos WHERE \`Nº de Cliente\`=$ID;
INSERT INTO chk SELECT (ROW_COUNT()=$NC);
COMMIT;" >/dev/null; then
  echo 'FALHOU: transacção revertida (bloqueio de outra sessão ou alteração entretanto). Nada foi apagado.' >&2; exit 1
fi
[[ "$(sql "SELECT COUNT(*) FROM Contractos WHERE \`Nº de Cliente\`=$ID;")" == 0 ]] || { echo 'ATENÇÃO: ainda há prestações do cliente.' >&2; exit 1; }
echo "ELIMINADO: $NC prestação(ões) e $NE movimento(s) do mutuário ID $ID."
echo "Cópia para reposição: $out"
echo "Para repor: bash deploy/delete-test-borrower.sh --restaurar $out"
