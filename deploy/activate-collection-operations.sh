#!/usr/bin/env bash
# CAPTA: ACTIVAR todas as operações de cobrança (pagamento parcial, liquidação do saldo, renovação dos juros,
# capitalização e prorrogação 15→30 dias). Acrescenta ao escritor de contratos a permissão de actualizar
# as colunas de datas, capital, taxa e prazo da prestação. Não altera dados.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql(){ printf '%s\n' "$1" | timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
echo '=== CAPTA | ACTIVAR OPERAÇÕES DE COBRANÇA ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || { echo 'BLOQUEADO: base inesperada.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM mysql.user WHERE User='cis_contract_writer';")" == 1 ]] || {
  echo 'BLOQUEADO: escritor de contratos inexistente. Execute primeiro bash deploy/activate-contract-cash-flows.sh' >&2; exit 1; }
for c in 'Data de Cobrança' 'Data de Inicio' 'Data Final' 'Capital' 'Amortização' '% de Juro' 'Prazo/Duração'; do
  [[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos' AND COLUMN_NAME='$c';")" == 1 ]] || {
    echo "BLOQUEADO: coluna «$c» inexistente em Contractos." >&2; exit 1; }
done
sql "GRANT UPDATE (\`Valor de Juros\`,\`Valor de Multa\`,\`Valor a Pagar\`,\`Valor Pago\`,
 \`Data de Pagamento\`,\`Estado\`,\`Dias\`,\`Situação de Pagamento\`,\`Estado do Contrato\`,
 \`Data de Cobrança\`,\`Data de Inicio\`,\`Data Final\`,\`Capital\`,\`Amortização\`,\`% de Juro\`,\`Prazo/Duração\`)
 ON \`cis\`.\`Contractos\` TO 'cis_contract_writer'@'%';"
grants="$(sql "SHOW GRANTS FOR 'cis_contract_writer'@'%';")"
[[ "$grants" != *'ALL PRIVILEGES'* ]] || { echo 'BLOQUEADO: ALL PRIVILEGES no escritor.' >&2; exit 1; }
[[ "$grants" != *'Nr do Contracto'* ]] || { echo 'BLOQUEADO: o escritor não pode alterar o número do contrato.' >&2; exit 1; }
n="$(sql "SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE=CONCAT(CHAR(39),'cis_contract_writer',CHAR(39),'@',CHAR(39),'%',CHAR(39)) AND TABLE_NAME='Contractos' AND PRIVILEGE_TYPE='UPDATE';")"
echo "Escritor de contratos: UPDATE em $n coluna(s) de Contractos."
[[ "$n" -ge 16 ]] || { echo 'BLOQUEADO: permissões não confirmadas.' >&2; exit 1; }
echo 'OPERAÇÕES DE COBRANÇA ACTIVAS. Próximo: bash deploy/refresh-private-preview.sh e Ctrl+F5.'
