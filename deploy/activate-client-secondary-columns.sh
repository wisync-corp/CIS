#!/usr/bin/env bash
# Adiciona 7 campos opcionais à tabela Clientes, sem criar tabelas adicionais.
# A execução é deliberadamente manual, após backup e verificação do esquema.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "Falta deploy/.env" >&2; exit 1; }
command -v openssl >/dev/null || { echo "Falta openssl" >&2; exit 1; }
[[ "$(grep -Ec '^CIS_CLIENTES_PREVIEW_APPROVED=true$' deploy/.env || true)" == 1 ]] || {
 echo "A consulta privada de Clientes não está aprovada." >&2; exit 1;
}
backup_dir="$HOME/cis-private/backups"
if [[ ! -d "$backup_dir" ]] || ! find "$backup_dir" -maxdepth 1 -type f -name 'cis-before-auth-*.sql' -size +0c -mmin -1440 -print -quit | grep -q .; then
 echo "Execute primeiro: bash deploy/backup-before-auth.sh (backup com menos de 24h)" >&2; exit 1
fi
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
mysql_sql() {
 "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$1"' _ "$1"
}
db_name="$(grep '^MYSQL_DATABASE=' deploy/.env | tail -n 1 | cut -d= -f2-)"
if [[ -n "$db_name" && "$db_name" != "cis" ]]; then
 echo "Base inesperada. A implementação foi preparada para cis, não $db_name." >&2; exit 1
fi
cols="ponto_referencia,local_trabalho_actividade,fonte_rendimento_principal,contacto_alternativo,pessoa_referencia_nome,pessoa_referencia_contacto,melhor_horario_contacto"
# Não tocar em campos já existentes nem assumir um motor transaccional.
meta="$(mysql_sql "SELECT CONCAT(COALESCE((SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes'),'MISSING'),':', (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND COLUMN_NAME IN ('ponto_referencia','local_trabalho_actividade','fonte_rendimento_principal','contacto_alternativo','pessoa_referencia_nome','pessoa_referencia_contacto','melhor_horario_contacto')),':', (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND COLUMN_NAME='ID'));")"
if [[ "$meta" != "InnoDB:0:1" ]]; then
 echo "BLOQUEADO: requisito InnoDB, sete colunas ainda ausentes e ID presente." >&2
 echo "Metadados (motor:colunas_existentes:ID) = $meta" >&2
 echo "Não execute ALTER manualmente; é necessário rever a tabela real." >&2
 exit 1
fi
before="$(mysql_sql "SELECT COUNT(*) FROM \`Clientes\`;")"
echo "Antes: $before registos Clientes; nenhuma tabela secundária será criada."
read -r -p "Aplicar os 7 campos opcionais directamente em Clientes? Escreva ADICIONAR: " confirm
[[ "$confirm" == "ADICIONAR" ]] || { echo "Cancelado"; exit 1; }
mysql_sql "ALTER TABLE \`Clientes\`
 ADD COLUMN \`ponto_referencia\` VARCHAR(255) NULL,
 ADD COLUMN \`local_trabalho_actividade\` VARCHAR(180) NULL,
 ADD COLUMN \`fonte_rendimento_principal\` VARCHAR(180) NULL,
 ADD COLUMN \`contacto_alternativo\` VARCHAR(40) NULL,
 ADD COLUMN \`pessoa_referencia_nome\` VARCHAR(180) NULL,
 ADD COLUMN \`pessoa_referencia_contacto\` VARCHAR(40) NULL,
 ADD COLUMN \`melhor_horario_contacto\` VARCHAR(100) NULL;"
after="$(mysql_sql "SELECT COUNT(*) FROM \`Clientes\`;")"
count="$(mysql_sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND COLUMN_NAME IN ('ponto_referencia','local_trabalho_actividade','fonte_rendimento_principal','contacto_alternativo','pessoa_referencia_nome','pessoa_referencia_contacto','melhor_horario_contacto');")"
if [[ "$before" != "$after" || "$count" != "7" ]]; then
 echo "ALERTA: inspeccionar a tabela imediatamente. Antes=$before Depois=$after Colunas=$count" >&2; exit 2
fi
echo "Concluído: $after clientes preservados, 7 campos novos opcionais na MESMA tabela Clientes."
echo "A gravação continua bloqueada até à configuração das permissões do editor."
