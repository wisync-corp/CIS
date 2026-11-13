#!/usr/bin/env bash
# Verificacao somente leitura antes de criar as tabelas Laravel.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: falta deploy/.env" >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
SQL="SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','personal_access_tokens','password_reset_tokens','client_drafts','clients','migrations') ORDER BY TABLE_NAME;"
echo "A verificar conflitos com tabelas do Laravel:"
result=$("${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$1"' _ "$SQL")
if [[ -n "$result" ]]; then
  printf '%s\n' "$result"
  echo "ATENCAO: ha tabelas Laravel existentes. Nao executar migracoes antes de analisar." >&2
  exit 2
fi
echo "Nenhum nome de tabela Laravel detectado no destino."
echo "Esta verificacao nao modifica dados; criar/validar backup antes de qualquer migracao."
