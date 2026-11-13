#!/usr/bin/env bash
# Apenas diagnóstico; NÃO lê o conteúdo do dump nem importa dados.
set -euo pipefail
cd "$(dirname "$0")/.."
DUMP="${1:-}"
if [[ -z "$DUMP" || ! -f "$DUMP" ]]; then
  echo "Uso: bash deploy/preflight-import.sh /caminho/privado/backup.sql" >&2
  exit 2
fi
if [[ ! -s "$DUMP" ]]; then
  echo "ERRO: ficheiro de exportação vazio." >&2
  exit 1
fi
if [[ ! -f deploy/.env ]]; then
  echo "ERRO: deploy/.env privado não encontrado." >&2
  exit 1
fi
echo "Ficheiro SQL presente (sem mostrar conteúdo)."
sha256sum "$DUMP" | awk '{print "SHA-256: "$1}'
echo "A verificar estado do MySQL e existência das tabelas de destino..."
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
"${COMPOSE[@]}" ps
SQL="SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('Usuarios','Clientes','Contractos','Extracto') ORDER BY TABLE_NAME;"
# As credenciais permanecem no ambiente interno do contentor.
"${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$1"' _ "$SQL" |
while IFS= read -r table; do
  if [[ -n "$table" ]]; then
    echo "AVISO: tabela existente na base de destino: $table"
  fi
done
echo "PRÉ-VERIFICAÇÃO CONCLUÍDA. NENHUMA IMPORTAÇÃO EXECUTADA."
echo "Antes de restaurar, confirmar manualmente a ausência de colisões, backup e compatibilidade SQL 5.5 -> 8.0."
