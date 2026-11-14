#!/usr/bin/env bash
# Cria APENAS as duas tabelas aprovadas para o CIS.
# Não altera Clientes, Contractos, Extracto nem Usuarios.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: deploy/.env não encontrado." >&2; exit 1; }
backup_dir="$HOME/cis-private/backups"
if [[ ! -d "$backup_dir" ]] || ! find "$backup_dir" -maxdepth 1 -type f -name 'cis-before-auth-*.sql' -size +0c -mmin -1440 -print -quit | grep -q .; then
 echo "BLOQUEADO: execute primeiro bash deploy/backup-before-auth.sh (backup <24h)." >&2; exit 1
fi
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql(){
 "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE" -e "$1"' _ "$1"
}
if [[ "$(sql 'SELECT DATABASE();')" != "cis" ]]; then
 echo 'BLOQUEADO: esta migração foi aprovada exclusivamente para a base cis.' >&2; exit 1
fi
check="$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('cis_tabela_juros','cis_tipos_movimento');")"
if [[ "$check" != 0 ]]; then
 echo "BLOQUEADO: $check tabela(s) já existem. Não recriar ou substituir dados." >&2; exit 1
fi
before="$(sql 'SELECT CONCAT((SELECT COUNT(*) FROM `Clientes`),":",(SELECT COUNT(*) FROM `Contractos`),":",(SELECT COUNT(*) FROM `Extracto`),":",(SELECT COUNT(*) FROM `Usuarios`));')"
echo "Registos originais antes (Clientes:Contractos:Extracto:Usuarios): $before"
echo 'A adicionar na MESMA base cis apenas:'
echo '  cis_tabela_juros (16 linhas originais: limites, taxa e período numérico)'
echo '  cis_tipos_movimento (10 categorias originais: Entradas/Saídas e descrição)'
echo 'A operação não autoriza ainda cálculos, desembolsos ou escrita no Extracto.'
read -r -p 'Confirma a criação destas DUAS tabelas? Escreva CRIAR-REFERENCIAS: ' approval
[[ "$approval" == "CRIAR-REFERENCIAS" ]] || { echo 'Cancelado; sem alterações.'; exit 0; }
sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.api-test.yaml \
 run --rm --no-deps --build api-test php artisan migrate --force \
 --path=database/migrations/2026_11_14_000010_create_cis_financial_reference_tables.php
after="$(sql 'SELECT CONCAT((SELECT COUNT(*) FROM `Clientes`),":",(SELECT COUNT(*) FROM `Contractos`),":",(SELECT COUNT(*) FROM `Extracto`),":",(SELECT COUNT(*) FROM `Usuarios`));')"
counts="$(sql 'SELECT CONCAT((SELECT COUNT(*) FROM `cis_tabela_juros`),":",(SELECT COUNT(*) FROM `cis_tipos_movimento`));')"
if [[ "$before" != "$after" || "$counts" != "16:10" ]]; then
 echo "ALERTA: verificar imediatamente. Originais antes=$before, depois=$after; referência=$counts" >&2; exit 2
fi
echo "SUCESSO: 2 novas tabelas criadas (16 linhas de juros, 10 tipos de movimento)."
echo "Contagens das 4 tabelas legadas preservadas: $after"
echo 'Recrie api-preview e web-preview para consultar em Definições.'
