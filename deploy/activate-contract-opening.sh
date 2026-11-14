#!/usr/bin/env bash
# Activação EXPLÍCITA da abertura real de contratos (Contractos.frm).
# Não altera tabelas nem registos legados; cria um utilizador MySQL separado
# com SELECT restrito e INSERT apenas em Contractos e Extracto.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: falta deploy/.env'; exit 1; }
grep -qx 'CIS_CONTRACTS_READ_APPROVED=true' deploy/.env || { echo 'BLOQUEADO: falta consulta autorizada de contratos.'; exit 1; }
grep -qx 'CIS_CLIENTES_PREVIEW_APPROVED=true' deploy/.env || { echo 'BLOQUEADO: falta consulta autorizada de clientes.'; exit 1; }
for key in CONTRACT_WRITER_DB_PASSWORD CONTRACT_WRITER_DB_USERNAME CIS_NEW_CONTRACTS_APPROVED; do
 if grep -q "^$key=" deploy/.env; then
  echo "BLOQUEADO: $key já está configurada; rever manualmente, sem duplicar." >&2; exit 1
 fi
done
backup_dir="$HOME/cis-private/backups"
if [[ ! -d "$backup_dir" ]] || ! find "$backup_dir" -maxdepth 1 -type f -name 'cis-before-auth-*.sql' -size +0c -mmin -1440 -print -quit | grep -q .; then
 echo 'BLOQUEADO: primeiro execute bash deploy/backup-before-auth.sh (backup recente).' >&2; exit 1
fi
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql() {
 printf '%s\n' "$1" | "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'
}
if [[ "$(sql 'SELECT DATABASE();')" != 'cis' ]]; then
 echo 'BLOQUEADO: operação permitida apenas para a base cis.' >&2; exit 1
fi
if [[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('Clientes','Contractos','Extracto','cis_tabela_juros');")" != '4' ]]; then
 echo 'BLOQUEADO: falta alguma das tabelas necessárias.' >&2; exit 1
fi
if [[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='Contractos' AND COLUMN_NAME='Ordem' AND EXTRA LIKE '%auto_increment%') OR (TABLE_NAME='Extracto' AND COLUMN_NAME='Nr' AND EXTRA LIKE '%auto_increment%'));" )" != '2' ]]; then
 echo 'BLOQUEADO: falta AUTO_INCREMENT no esquema original.' >&2; exit 1
fi
if [[ "$(sql "SELECT COUNT(*) FROM mysql.user WHERE User='cis_contract_writer';")" != '0' ]]; then
 echo 'BLOQUEADO: utilizador cis_contract_writer já existe; verificar manualmente.' >&2; exit 1
fi
echo 'A executar testes financeiros sem gravação, antes de conceder permissões…'
sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.api-test.yaml \\
 run --rm --no-deps --build api-test php tests/contract_opening_pricing_smoke.php
password="$(openssl rand -hex 32)"
echo 'Operação a activar:'
echo ' - Criação de contratos e suas prestações em Contractos'
echo ' - Um movimento Saídas / Desembolso de Empréstimo em Extracto'
echo ' - Verificação prévia de saldo e da tabela de preços'
echo ' - Transacção atómica; não altera contratos e movimentos antigos'
echo ' - Apenas administrador, via pré-visualização privada'
echo 'ATENÇÃO: isto permite registos financeiros reais. Confirme que o saldo do Extracto foi reconciliado.'
read -r -p 'Autorizar abertura e desembolso reais? Escreva ACTIVAR-ABERTURA: ' confirm
[[ "$confirm" == 'ACTIVAR-ABERTURA' ]] || { echo 'Cancelado, sem alterações.'; exit 0; }
umask 077
sql "CREATE USER 'cis_contract_writer'@'%' IDENTIFIED BY '$password';
GRANT SELECT ON \`cis\`.\`Clientes\` TO 'cis_contract_writer'@'%';
GRANT SELECT ON \`cis\`.\`cis_tabela_juros\` TO 'cis_contract_writer'@'%';
GRANT SELECT, INSERT ON \`cis\`.\`Contractos\` TO 'cis_contract_writer'@'%';
GRANT SELECT, INSERT ON \`cis\`.\`Extracto\` TO 'cis_contract_writer'@'%';" >/dev/null
{
 printf '\nCONTRACT_WRITER_DB_USERNAME=cis_contract_writer\n'
 printf 'CONTRACT_WRITER_DB_PASSWORD=%s\n' "$password"
 printf 'CIS_NEW_CONTRACTS_APPROVED=true\n'
} >> deploy/.env
chmod 600 deploy/.env
echo 'Configuração guardada no ficheiro privado deploy/.env. NÃO publique nem envie o ficheiro.'
echo 'Recrie api-preview e web-preview para activar as rotas; teste primeiro com controlo de caixa.'
