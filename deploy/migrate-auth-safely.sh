#!/usr/bin/env bash
# Cria apenas tabelas novas do Laravel, com salvaguardas. Nao altera tabelas legadas.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: falta deploy/.env." >&2; exit 1; }
command -v openssl >/dev/null || { echo "ERRO: openssl indisponivel." >&2; exit 1; }
backup_dir="$HOME/cis-private/backups"
if [[ ! -d "$backup_dir" ]] || ! find "$backup_dir" -maxdepth 1 -type f -name 'cis-before-auth-*.sql' -size +0c -mmin -1440 -print -quit | grep -q .; then
  echo "ERRO: nao foi encontrado um backup recente (24h) e nao vazio." >&2
  echo "Execute primeiro: bash deploy/backup-before-auth.sh" >&2
  exit 1
fi
echo "A verificar se o esquema Laravel ainda nao existe..."
bash deploy/preflight-auth-schema.sh
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
get_counts() {
  local sql="SELECT CONCAT((SELECT COUNT(*) FROM \`Clientes\`), ':', (SELECT COUNT(*) FROM \`Contractos\`), ':', (SELECT COUNT(*) FROM \`Extracto\`), ':', (SELECT COUNT(*) FROM \`Usuarios\`));"
  "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$1"' _ "$sql"
}
before="$(get_counts)"
echo "Registos antes (Clientes:Contractos:Extracto:Usuarios): $before"
echo "O Laravel criara tabelas proprias (users, personal_access_tokens, password_reset_tokens, client_drafts, clients, migrations)."
echo "As 4 tabelas originais nao devem ser alteradas."
read -r -p "Confirma a criacao destas novas tabelas na base cis? Escreva CRIAR: " approval
if [[ "$approval" != "CRIAR" ]]; then
  echo "Cancelado, sem migracoes."
  exit 1
fi
export APP_KEY="base64:$(openssl rand -base64 32)"
echo "A executar migracoes Laravel sem publicar portas..."
sudo --preserve-env=APP_KEY docker compose --env-file deploy/.env \
  -f deploy/mysql-only.yaml -f deploy/compose.api-test.yaml \
  run --rm --no-deps --build api-test php artisan migrate --force
after="$(get_counts)"
echo "Registos depois (Clientes:Contractos:Extracto:Usuarios): $after"
if [[ "$after" != "$before" ]]; then
  echo "ALERTA: contagens originais divergentes. Nao activar API; investigar." >&2
  exit 2
fi
echo "Migracoes concluidas; contagens originais preservadas."
echo "Nao foram criadas contas de utilizador nem activado acesso publico."
