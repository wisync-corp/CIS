#!/usr/bin/env bash
# Activação integrada e CONTROLADA dos três fluxos: abertura semanal,
# cobrança total e testes de criação/desembolso — uma confirmação.
# Executar no VPS; nunca no computador do assistente.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: deploy/.env inexistente' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql(){
 printf '%s\n' "$1" | "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'
}
check(){
 [[ "$1" == "$2" ]] || { echo "BLOQUEADO: $3" >&2; exit 1; }
}
check "$(sql 'SELECT DATABASE();')" 'cis' 'o esquema activo não é cis.'
check "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('Clientes','Contractos','Extracto','cis_tabela_juros') AND ENGINE='InnoDB';")" '4' 'faltam tabelas ou alguma não é InnoDB.'
check "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='Contractos' AND COLUMN_NAME='Ordem' AND EXTRA LIKE '%auto_increment%') OR (TABLE_NAME='Extracto' AND COLUMN_NAME='Nr' AND EXTRA LIKE '%auto_increment%'));")" '2' 'IDs antigos não estão protegidos por AUTO_INCREMENT.'
grep -qx 'CIS_CLIENTES_PREVIEW_APPROVED=true' deploy/.env || { echo 'BLOQUEADO: consulta de clientes não autorizada.' >&2; exit 1; }
grep -qx 'CIS_CONTRACTS_READ_APPROVED=true' deploy/.env || { echo 'BLOQUEADO: consulta de contratos não autorizada.' >&2; exit 1; }
if [[ "$(sql "SELECT COUNT(*) FROM Contractos WHERE \`Valor Pago\` < 0 OR \`Capital\` < 0 OR \`Amortização\` < 0;")" != '0' ]]; then
 echo 'BLOQUEADO: movimentos financeiros negativos na tabela Contractos; reconciliar.' >&2; exit 1
fi
echo 'A criar backup integral e protegido antes de alterar a base…'
bash deploy/backup-before-auth.sh
echo 'A executar os testes financeiros SEM registos reais…'
for test_file in contract_opening_pricing_smoke.php contract_calculation_strategy_smoke.php financial_workflows_smoke.php; do
 sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.api-test.yaml run --rm --no-deps --build api-test php "tests/$test_file"
done
echo
echo 'A activação inclui:'
echo '  1. Geração de prestações semanais (juros fixos e variável/parcela única).'
echo '  2. Liquidação TOTAL de prestações de contratos CIS novos, com entrada no Extracto.'
echo '  3. Abertura de contrato e desembolso em transacção atómica.'
echo '  4. Estratégias adicionais com juros inteiros arredondados para cima.'
echo 'Permanecem BLOQUEADOS: cobrança parcial, renovação e capitalização composta semanal.'
echo 'Os valores reais do Extracto devem estar reconciliados com caixa/banco.'
echo 'O Excel original e outras aplicações que ESCREVEM na base devem estar parados.'
read -r -p 'Após confirmar saldo, backup, ausência de outros escritores e testes: escreva ACTIVAR-FLUXOS: ' approval
[[ "$approval" == 'ACTIVAR-FLUXOS' ]] || { echo 'Cancelado; nenhuma configuração financeira activada.'; exit 0; }
umask 077
if [[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos' AND COLUMN_NAME='Estratégia de Cálculo';")" == '0' ]]; then
 sql "ALTER TABLE \`Contractos\` ADD COLUMN \`Estratégia de Cálculo\` VARCHAR(90) NULL COMMENT 'Fórmula CIS; contratos antigos NULL';"
fi
# Caso exista credencial anterior, reutilizá-la; não a expor no terminal.
if grep -q '^CONTRACT_WRITER_DB_PASSWORD=' deploy/.env; then
 grep -qx 'CONTRACT_WRITER_DB_USERNAME=cis_contract_writer' deploy/.env || {
    echo 'BLOQUEADO: credenciais anteriores incompatíveis. Rever manualmente.' >&2; exit 1;
 }
else
 check "$(sql "SELECT COUNT(*) FROM mysql.user WHERE User='cis_contract_writer';")" '0' 'o utilizador financeiro existe, mas a password não está no .env.'
 secret="$(openssl rand -hex 32)"
 sql "CREATE USER 'cis_contract_writer'@'%' IDENTIFIED BY '$secret';"
 {
   printf '\nCONTRACT_WRITER_DB_USERNAME=cis_contract_writer\n'
   printf 'CONTRACT_WRITER_DB_PASSWORD=%s\n' "$secret"
 } >> deploy/.env
fi
sql "GRANT SELECT ON \`cis\`.\`Clientes\` TO 'cis_contract_writer'@'%';
GRANT SELECT ON \`cis\`.\`cis_tabela_juros\` TO 'cis_contract_writer'@'%';
GRANT SELECT, INSERT ON \`cis\`.\`Extracto\` TO 'cis_contract_writer'@'%';
GRANT SELECT, INSERT ON \`cis\`.\`Contractos\` TO 'cis_contract_writer'@'%';
GRANT UPDATE (\`Valor de Juros\`,\`Valor de Multa\`,\`Valor a Pagar\`,\`Valor Pago\`,
 \`Data de Pagamento\`,\`Estado\`,\`Dias\`,\`Situação de Pagamento\`,
 \`Estado do Contrato\`) ON \`cis\`.\`Contractos\` TO 'cis_contract_writer'@'%';"
upsert_flag(){
 local name="$1"
 if grep -q "^$name=" deploy/.env; then
  sed -i "s/^$name=.*/$name=true/" deploy/.env
 else
  printf '%s=true\n' "$name" >> deploy/.env
 fi
}
upsert_flag CIS_NEW_CONTRACTS_APPROVED
upsert_flag CIS_EXTENDED_CALCULATIONS_APPROVED
upsert_flag CIS_WEEKLY_CONTRACTS_APPROVED
upsert_flag CIS_COLLECTION_WRITES_APPROVED
chmod 600 deploy/.env
echo 'A reconstruir API e frontend…'
sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml up -d --no-deps --build --force-recreate api-preview web-preview
echo 'A verificar as rotas e configurações activas dentro do container…'
sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml exec -T api-preview php artisan route:list --path=api/cis/contractos >/dev/null
sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml exec -T api-preview php -r '
require "/srv/cis/vendor/autoload.php";
$app=require "/srv/cis/bootstrap/app.php";
$app->make(\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
foreach (["CIS_NEW_CONTRACTS_APPROVED","CIS_EXTENDED_CALCULATIONS_APPROVED","CIS_WEEKLY_CONTRACTS_APPROVED","CIS_COLLECTION_WRITES_APPROVED"] as $key) {
  if (!filter_var(env($key,false),FILTER_VALIDATE_BOOLEAN)) {fwrite(STDERR,"DESACTIVADO: ".$key."\n");exit(3);}
}
$db=\Illuminate\\Support\\Facades\\DB::connection("mysql_contract_writer");
$db->select("SELECT 1 AS ok");
if (!$db->getSchemaBuilder()->hasColumn("Contractos","Estratégia de Cálculo"))exit(4);
echo "CONFIGURAÇÃO PRONTA: 4 sinalizadores, ligação writer e coluna de estratégia.\n";
'
echo 'CONFIGURAÇÃO APLICADA. O primeiro desembolso e a primeira cobrança reais só se confirmam após transacções efectuadas pelo operador; este script não cria operações fictícias.'
