#!/usr/bin/env bash
# Cria APENAS as tabelas de informacoes complementares e auditoria.
# Não executa migrações gerais nem altera as quatro tabelas importadas.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: deploy/.env em falta." >&2; exit 1; }
grep -qx 'CIS_CLIENTES_PREVIEW_APPROVED=true' deploy/.env || {
  echo "ERRO: primeiro autorize o acesso privado ao cadastro de Clientes." >&2; exit 1;
}
if grep -q '^CIS_CLIENTES_SECONDARY_APPROVED=' deploy/.env; then
  echo "Já existe a opção CIS_CLIENTES_SECONDARY_APPROVED. Verifique-a manualmente, sem duplicar linhas." >&2
  exit 1
fi
backup_dir="$HOME/cis-private/backups"
if [[ ! -d "$backup_dir" ]] || ! find "$backup_dir" -maxdepth 1 -type f -name 'cis-before-auth-*.sql' -size +0c -mmin -1440 -print -quit | grep -q .; then
  echo "ERRO: não foi encontrado backup SQL recente (últimas 24h). Execute bash deploy/backup-before-auth.sh" >&2
  exit 1
fi
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
check_sql="SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('cis_client_secondary_info','cis_client_secondary_audit');"
found=$("${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$1"' _ "$check_sql")
if [[ "$found" != "0" ]]; then
  echo "BLOQUEADO: uma ou mais tabelas complementares já existem. Não repetir migração." >&2
  exit 1
fi
echo "Serão acrescentadas 2 tabelas novas na base cis: cis_client_secondary_info e cis_client_secondary_audit."
echo "Nenhuma coluna será adicionada à tabela Clientes e nenhum registo existente será modificado."
read -r -p "Autoriza a criação destas tabelas e o tratamento das novas informações pessoais? Escreva CRIAR: " confirm
[[ "$confirm" == "CRIAR" ]] || { echo "Cancelado."; exit 1; }
echo "A executar apenas a migração das tabelas complementares..."
sudo docker compose --env-file deploy/.env \
 -f deploy/mysql-only.yaml -f deploy/compose.api-test.yaml \
 run --rm --no-deps --build api-test php artisan migrate --force \
 --path=database/migrations/2026_11_13_000001_create_client_secondary_info.php
printf '\nCIS_CLIENTES_SECONDARY_APPROVED=true\n' >> deploy/.env
chmod 600 deploy/.env
echo "Pronto: informações complementares autorizadas apenas no ambiente SSH privado."
echo "Aplique o código: sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml up -d --no-deps --build --force-recreate api-preview web-preview"
