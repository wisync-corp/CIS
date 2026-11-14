#!/usr/bin/env bash
# Conversao CONTROLADA de apenas dois campos do cadastro, na MESMA tabela Clientes.
# Nunca imprime os horarios existentes; recusa formatos ambiguos e evita alteracoes sem backup.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: deploy/.env em falta." >&2; exit 1; }
backup_dir="$HOME/cis-private/backups"
if [[ ! -d "$backup_dir" ]] || ! find "$backup_dir" -maxdepth 1 -type f -name 'cis-before-auth-*.sql' -size +0c -mmin -1440 -print -quit | grep -q .; then
 echo "BLOQUEADO: backup recente em falta. Executar bash deploy/backup-before-auth.sh" >&2; exit 1
fi
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql() {
 "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 -N -B "$MYSQL_DATABASE" -e "$1"' _ "$1"
}
echo "A verificar exclusivamente as colunas validade_documento e melhor_horario_contacto..."
engine="$(sql "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes';")"
[[ "$engine" == "InnoDB" ]] || { echo "BLOQUEADO: motor esperado InnoDB, encontrado $engine." >&2; exit 1; }
rows_before="$(sql 'SELECT COUNT(*) FROM `Clientes`;')"
[[ "$rows_before" == 122 ]] || { echo "BLOQUEADO: contagem inesperada de clientes ($rows_before). Rever antes de prosseguir." >&2; exit 1; }
date_type="$(sql "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND COLUMN_NAME='validade_documento';")"
time_type="$(sql "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND COLUMN_NAME='melhor_horario_contacto';")"
[[ "$date_type" == "varchar" || "$date_type" == "date" ]] || { echo "BLOQUEADO: validade_documento tem tipo $date_type (esperado VARCHAR/DATE)." >&2; exit 1; }
[[ "$time_type" == "varchar" || "$time_type" == "time" ]] || { echo "BLOQUEADO: melhor_horario_contacto tem tipo $time_type (esperado VARCHAR/TIME)." >&2; exit 1; }
if [[ "$date_type" == "date" && "$time_type" == "time" ]]; then
 echo "OK: ambos os tipos já estão correctos. Nenhuma operação necessária."; exit 0
fi
if [[ "$date_type" == "varchar" ]]; then
 filled_dates="$(sql "SELECT COUNT(*) FROM \`Clientes\` WHERE validade_documento IS NOT NULL AND TRIM(validade_documento) <> '';")"
 [[ "$filled_dates" == 0 ]] || { echo "BLOQUEADO: validade_documento contém $filled_dates valores que exigem avaliação antes de converter." >&2; exit 1; }
fi
if [[ "$time_type" == "varchar" ]]; then
 # Aceita apenas hora local HH:MM ou HH:MM:SS em 24h. Proíbe texto livre,
 # indicação AM/PM, horas superiores a 23 e minutos/segundos superiores a 59.
 invalid_times="$(sql "SELECT COUNT(*) FROM \`Clientes\` WHERE melhor_horario_contacto IS NOT NULL AND TRIM(melhor_horario_contacto) <> '' AND TRIM(melhor_horario_contacto) NOT REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$';")"
 present_times="$(sql "SELECT COUNT(*) FROM \`Clientes\` WHERE melhor_horario_contacto IS NOT NULL AND TRIM(melhor_horario_contacto) <> '';")"
 if [[ "$invalid_times" != 0 ]]; then
  echo "BLOQUEADO: $invalid_times horario(s) com formato incompatível com TIME; $present_times preenchido(s)."
  echo "Nenhuma alteração foi efectuada. Precisamos definir a interpretação do valor antigo."
  exit 1
 fi
fi
echo "Validação OK: $rows_before clientes. Data: $date_type -> DATE; horário: $time_type -> TIME."
if [[ "$time_type" == "varchar" ]]; then echo "Horários preenchidos com formato HH:MM[:SS] válido: $present_times."; fi
echo "Será feita conversão de colunas existentes; sem novas tabelas ou campos duplicados."
read -r -p "Confirma alteração real dos tipos de dados? Escreva CONVERTER: " confirm
[[ "$confirm" == "CONVERTER" ]] || { echo "Cancelado sem alterações."; exit 0; }
# Em SQL STRICT, strings vazias não podem ser convertidas directamente para DATE/TIME.
# Valores vazios são normalizados para NULL antes de alterar o tipo.
# NOTA: ALTER TABLE (DDL) faz commit implícito; se falhar, parar e usar o backup.
if [[ "$date_type" == "varchar" ]]; then
 sql "UPDATE \`Clientes\` SET validade_documento=NULL WHERE validade_documento IS NOT NULL AND TRIM(validade_documento)='';"
 sql "ALTER TABLE \`Clientes\` MODIFY COLUMN validade_documento DATE NULL;"
fi
if [[ "$time_type" == "varchar" ]]; then
 sql "UPDATE \`Clientes\` SET melhor_horario_contacto=NULL WHERE melhor_horario_contacto IS NOT NULL AND TRIM(melhor_horario_contacto)='';"
 # Uniformizar o único formato de origem previsto antes de converter.
 sql "UPDATE \`Clientes\` SET melhor_horario_contacto=CONCAT(TRIM(melhor_horario_contacto),':00') WHERE melhor_horario_contacto IS NOT NULL AND CHAR_LENGTH(TRIM(melhor_horario_contacto))=5;"
 sql "ALTER TABLE \`Clientes\` MODIFY COLUMN melhor_horario_contacto TIME NULL;"
fi
rows_after="$(sql 'SELECT COUNT(*) FROM `Clientes`;')"
final_date="$(sql "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND COLUMN_NAME='validade_documento';")"
final_time="$(sql "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND COLUMN_NAME='melhor_horario_contacto';")"
if [[ "$rows_after" != "$rows_before" || "$final_date" != date || "$final_time" != time ]]; then
 echo "ALERTA: validar o esquema e restaurabilidade do backup. Antes=$rows_before Depois=$rows_after DATE=$final_date TIME=$final_time" >&2
 exit 2
fi
echo "SUCESSO: $rows_after clientes preservados; validade_documento DATE e melhor_horario_contacto TIME."
