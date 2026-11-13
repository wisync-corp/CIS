#!/usr/bin/env bash
# Backup seguro antes da criacao das tabelas de autenticacao Laravel.
# Nao imprime palavras-passe nem linhas de clientes.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "Falta deploy/.env" >&2; exit 1; }
umask 077
backup_dir="$HOME/cis-private/backups"
mkdir -p "$backup_dir"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
target="$backup_dir/cis-before-auth-$stamp.sql"
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
echo "A criar copia de seguranca privada da base cis..."
# Dump entregue pelo container atraves de stdout para ficheiro local protegido.
if ! "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --quick --set-gtid-purged=OFF --default-character-set=utf8mb4 "$MYSQL_DATABASE"' > "$target"; then
  rm -f "$target"
  echo "ERRO ao criar copia; nenhum ficheiro incompleto foi mantido." >&2
  exit 1
fi
[[ -s "$target" ]] || { rm -f "$target"; echo "Backup vazio, interrompido." >&2; exit 1; }
chmod 600 "$target"
echo "Copia guardada em: $target"
echo "Tamanho: $(du -h "$target" | cut -f1)"
echo "SHA-256: $(sha256sum "$target" | cut -d' ' -f1)"
echo "NENHUMA alteracao efectuada na base. Nao envie este ficheiro para GitHub/chat."
