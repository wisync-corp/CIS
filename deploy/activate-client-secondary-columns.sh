#!/usr/bin/env bash
# Completar a tabela Clientes, sem criar tabelas de dados adicionais.
# Adiciona apenas colunas realmente ausentes, preservando os 122 registos originais.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: falta deploy/.env" >&2; exit 1; }
grep -qx 'CIS_CLIENTES_PREVIEW_APPROVED=true' deploy/.env || { echo "ERRO: acesso privado não aprovado." >&2; exit 1; }
backups="$HOME/cis-private/backups"
if [[ ! -d "$backups" ]] || ! find "$backups" -maxdepth 1 -type f -name 'cis-before-auth-*.sql' -size +0c -mmin -1440 -print -quit | grep -q .; then
 echo "ERRO: primeiro faça backup recente com bash deploy/backup-before-auth.sh" >&2; exit 1
fi
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql(){ "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$1"' _ "$1"; }
db_name="$(grep '^MYSQL_DATABASE=' deploy/.env | tail -n1 | cut -d= -f2-)"
[[ -z "$db_name" || "$db_name" == 'cis' ]] || { echo "Base inesperada: $db_name" >&2; exit 1; }
engine="$(sql "SELECT COALESCE((SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes'), 'MISSING');")"
[[ "$engine" == "InnoDB" ]] || { echo "BLOQUEADO: Clientes usa motor $engine, não InnoDB. Requer revisão prévia." >&2; exit 1; }
pk="$(sql "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND INDEX_NAME='PRIMARY';")"
pkid="$(sql "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND INDEX_NAME='PRIMARY' AND COLUMN_NAME='ID';")"
[[ "$pk" == 1 && "$pkid" == 1 ]] || { echo "BLOQUEADO: ID não é a única coluna da chave primária." >&2; exit 1; }
# Inclui os 7 campos complementares anteriormente aprovados e os 10 presentes nas imagens.
# NÃO duplica o BI, contacto principal, naturalidade, bairro, rua ou casa.
columns=(
 "ponto_referencia:255" "local_trabalho_actividade:180"
 "fonte_rendimento_principal:180" "contacto_alternativo:40"
 "pessoa_referencia_nome:180" "pessoa_referencia_contacto:40"
 "melhor_horario_contacto:100"
 "tipo_cliente:60" "genero:50" "profissao:150" "tipo_documento:80"
 "nuit:30" "local_emissao_documento:150" "validade_documento:10"
 "nome_pai:180" "nome_mae:180" "grau_parentesco_referencia:100"
)
alter=()
for item in "${columns[@]}"; do
 name="${item%%:*}"
 size="${item##*:}"
 # Only known column names from the local fixed allowlist are interpolated.
 found="$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND COLUMN_NAME='$name';")"
 if [[ "$found" == 1 ]]; then
  type="$(sql "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND COLUMN_NAME='$name';")"
  if [[ "$type" != "varchar" ]]; then
   echo "BLOQUEADO: coluna já existente $name tem tipo $type; rever manualmente." >&2; exit 1
  fi
  actual="$(sql "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND COLUMN_NAME='$name';")"
  if (( actual < size )); then
   echo "BLOQUEADO: coluna $name existente demasiado curta ($actual < $size)." >&2; exit 1
  fi
 elif [[ "$found" == 0 ]]; then
  alter+=("ADD COLUMN \`$name\` VARCHAR($size) NULL")
 else
  echo "BLOQUEADO: estado ambíguo da coluna $name." >&2; exit 1
 fi
done
if (( ${#alter[@]} == 0 )); then echo "Todas as 17 colunas já existem; nenhuma alteração necessária."; exit 0; fi
before="$(sql 'SELECT COUNT(*) FROM \`Clientes\`;')"
echo "Clientes existentes: $before. Colunas novas necessárias: ${#alter[@]}."
echo "Todas serão opcionais e adicionadas à MESMA tabela Clientes; nenhuma tabela auxiliar."
read -r -p "Autoriza apenas estas colunas em Clientes? Escreva ADICIONAR: " confirm
[[ "$confirm" == ADICIONAR ]] || { echo 'Cancelado.'; exit 1; }
sep=""
statement="ALTER TABLE \`Clientes\` "
for part in "${alter[@]}"; do statement+="$sep$part"; sep=", "; done
statement+=";"
sql "$statement"
after="$(sql 'SELECT COUNT(*) FROM \`Clientes\`;')"
[[ "$after" == "$before" ]] || { echo "ALERTA: contagem mudou ($before → $after); bloquear edição e investigar." >&2; exit 2; }
echo "OK: $after clientes preservados. Colunas acrescentadas: ${#alter[@]}."
echo "As permissões de edição mantêm-se independentes e exigem configuração específica."
