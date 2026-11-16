#!/usr/bin/env bash
# Acrescenta apenas uma coluna opcional na tabela financeira original.
# Não recalcula nem actualiza prestações históricas.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: falta deploy/.env' >&2; exit 1; }
backup_dir="$HOME/cis-private/backups"
if [[ ! -d "$backup_dir" ]] || ! find "$backup_dir" -maxdepth 1 -type f -name 'cis-before-auth-*.sql' -size +0c -mmin -1440 -print -quit | grep -q .; then
 echo 'BLOQUEADO: criar primeiro backup recente com bash deploy/backup-before-auth.sh' >&2
 exit 1
fi
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql() {
 printf '%s\n' "$1" | "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'
}
[[ "$(sql 'SELECT DATABASE();')" == 'cis' ]] || { echo 'BLOQUEADO: base de dados diferente de cis.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos';")" == '1' ]] || { echo 'BLOQUEADO: tabela Contractos não encontrada.' >&2; exit 1; }
existing="$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos' AND COLUMN_NAME='Estratégia de Cálculo';")"
if [[ "$existing" == '1' ]]; then
 echo 'A coluna Estratégia de Cálculo já existe. Nenhuma alteração necessária.'
 exit 0
fi
[[ "$existing" == '0' ]] || { echo 'BLOQUEADO: estado de esquema inesperado.' >&2; exit 1; }
echo 'Será adicionada UMA coluna VARCHAR(90) NULL à tabela Contractos.'
echo 'Linhas existentes conservam os valores originais e ficam com estratégia NULL.'
read -r -p 'Confirma a alteração aditiva do esquema? Escreva ADICIONAR-COLUNA: ' confirm
[[ "$confirm" == 'ADICIONAR-COLUNA' ]] || { echo 'Cancelado.'; exit 0; }
sql "ALTER TABLE \`Contractos\` ADD COLUMN \`Estratégia de Cálculo\` VARCHAR(90) NULL COMMENT 'Estratégia de cálculo CIS (NULL em contratos históricos)';"
echo 'Coluna criada. Rever em Contractos e condições de crédito.'
