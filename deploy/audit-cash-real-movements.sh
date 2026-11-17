#!/usr/bin/env bash
# CAPTA | auditoria estrutural da Caixa, somente SELECT.
# Não modifica contas, movimentos nem flags. Não substitui conciliação externa.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
COMPOSE=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql() {
 printf '%s\n' "$1" | timeout 35 "${COMPOSE[@]}" exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'
}
fail=0
zero() {
 local name="$1" result
 result="$(sql "$2")"
 echo "$name: $result"
 if [[ "$result" != 0 ]]; then fail=1; fi
}
echo '=== CAPTA / CAIXA / AUDITORIA SOMENTE LEITURA ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || { echo 'Base inesperada.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='operation_key';")" == 1 ]] || { echo 'operation_key em falta.' >&2; exit 1; }
zero 'Movimentos associados a conta inexistente' "SELECT COUNT(*) FROM Extracto e LEFT JOIN cis_cash_accounts a ON a.id=e.account_id WHERE e.account_id IS NOT NULL AND a.id IS NULL;"
zero 'Duplicações de chave idempotente' "SELECT COUNT(*) FROM (SELECT operation_key FROM Extracto WHERE operation_key IS NOT NULL GROUP BY operation_key HAVING COUNT(*)>1) duplicates;"
zero 'Operações gerais sem conta' "SELECT COUNT(*) FROM Extracto WHERE operation_key LIKE 'cash:%' AND account_id IS NULL;"
zero 'Operações CAPTA com valor inválido' "SELECT COUNT(*) FROM Extracto WHERE operation_key LIKE 'cash:%' AND (Valor IS NULL OR CAST(Valor AS DECIMAL(18,2))<=0);"
zero 'Operações CAPTA com natureza inválida' "SELECT COUNT(*) FROM Extracto WHERE operation_key LIKE 'cash:%' AND `Tipo de Movimento` NOT IN ('Entradas','Saídas');"
zero 'Contas activas com saldo inicial incompleto' "SELECT COUNT(*) FROM cis_cash_accounts WHERE status='activa' AND (opening_balance IS NULL OR opening_extracto_nr IS NULL OR validated_at IS NULL OR cut_at IS NULL);"
zero 'Pernas de transferências desequilibradas' "SELECT COUNT(*) FROM (
 SELECT SUBSTRING_INDEX(operation_key,':',2) raiz,
 COUNT(*) pernas,
 SUM(operation_key LIKE '%:1') origem,
 SUM(operation_key LIKE '%:2') destino,
 SUM(`Tipo de Movimento`='Saídas') saidas,
 SUM(`Tipo de Movimento`='Entradas') entradas,
 COUNT(DISTINCT account_id) contas,
 SUM(CASE WHEN `Tipo de Movimento`='Entradas' THEN CAST(Valor AS DECIMAL(18,2)) ELSE -CAST(Valor AS DECIMAL(18,2)) END) diferenca
 FROM Extracto WHERE operation_key LIKE 'cash:%'
 AND `Descrição` LIKE '[TRANSFER %'
 GROUP BY SUBSTRING_INDEX(operation_key,':',2)
 HAVING pernas<>2 OR origem<>1 OR destino<>1 OR saidas<>1 OR entradas<>1 OR contas<>2 OR diferenca<>0
 ) t;"
zero 'Estornos de transferência desequilibrados' "SELECT COUNT(*) FROM (
 SELECT SUBSTRING_INDEX(operation_key,':',4) raiz,
 COUNT(*) pernas,
 SUM(operation_key LIKE '%:1') origem,
 SUM(operation_key LIKE '%:2') destino,
 SUM(`Tipo de Movimento`='Saídas') saidas,
 SUM(`Tipo de Movimento`='Entradas') entradas,
 COUNT(DISTINCT account_id) contas,
 SUM(CASE WHEN `Tipo de Movimento`='Entradas' THEN CAST(Valor AS DECIMAL(18,2)) ELSE -CAST(Valor AS DECIMAL(18,2)) END) diferenca
 FROM Extracto WHERE operation_key LIKE 'cash:reverse:transfer:%'
 GROUP BY SUBSTRING_INDEX(operation_key,':',4)
 HAVING pernas<>2 OR origem<>1 OR destino<>1 OR saidas<>1 OR entradas<>1 OR contas<>2 OR diferenca<>0
 ) t;"
echo 'Contagens por atribuição de contas e estornos:'
sql "SELECT SUM(account_id IS NULL),SUM(account_id IS NOT NULL),SUM(operation_key LIKE 'cash:reverse:%') FROM Extracto;"
echo 'Saldo contabilístico por conta activa (MZN):'
sql "SELECT a.code,CAST(a.opening_balance AS DECIMAL(18,2))+
 COALESCE(SUM(CASE WHEN e.`Tipo de Movimento`='Entradas' THEN CAST(e.Valor AS DECIMAL(18,2)) WHEN e.`Tipo de Movimento`='Saídas' THEN -CAST(e.Valor AS DECIMAL(18,2)) ELSE 0 END),0) AS saldo
 FROM cis_cash_accounts a LEFT JOIN Extracto e ON e.account_id=a.id AND e.Nr>a.opening_extracto_nr
 WHERE a.status='activa' GROUP BY a.id,a.code,a.opening_balance ORDER BY a.code;"
zero 'Contas activas com saldo contabilístico negativo' "SELECT COUNT(*) FROM (
 SELECT a.id,CAST(a.opening_balance AS DECIMAL(18,2))+
 COALESCE(SUM(CASE WHEN e.`Tipo de Movimento`='Entradas' THEN CAST(e.Valor AS DECIMAL(18,2)) WHEN e.`Tipo de Movimento`='Saídas' THEN -CAST(e.Valor AS DECIMAL(18,2)) ELSE 0 END),0) saldo
 FROM cis_cash_accounts a LEFT JOIN Extracto e ON e.account_id=a.id AND e.Nr>a.opening_extracto_nr
 WHERE a.status='activa' GROUP BY a.id,a.opening_balance HAVING saldo<0
 ) negative;"
if [[ "$fail" -ne 0 ]]; then
 echo 'AUDITORIA REPROVADA: investigar sem alterar lançamentos.' >&2
 exit 1
fi
echo 'AUDITORIA ESTRUTURAL APROVADA. Nenhuma alteração efectuada.'
echo 'A conferência exige prova bancária ou contagem de numerário independente.'
