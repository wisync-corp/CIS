#!/usr/bin/env bash
# Aprovado: normalizar apenas valores 0000-00-00 do campo original Data de Emissão.
# Não modifica outras datas, campos, tabelas ou registos; sem divulgar PII.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: ficheiro privado deploy/.env não encontrado." >&2; exit 1; }
backups="$HOME/cis-private/backups"
if [[ ! -d "$backups" ]] || ! find "$backups" -maxdepth 1 -type f -name 'cis-before-auth-*.sql' -size +0c -mmin -1440 -print -quit | grep -q .; then
 echo "BLOQUEADO: faça um novo backup com bash deploy/backup-before-auth.sh" >&2
 exit 1
fi
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
mysql_read(){
 "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 -N -B "$MYSQL_DATABASE" -e "$1"' _ "$1"
}
type="$(mysql_read "SELECT CONCAT(DATA_TYPE,':',IS_NULLABLE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND COLUMN_NAME='Data de Emissão';")"
engine="$(mysql_read "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes';")"
rows="$(mysql_read 'SELECT COUNT(*) FROM `Clientes`;')"
invalid="$(mysql_read "SELECT COUNT(*) FROM \`Clientes\` WHERE CAST(\`Data de Emissão\` AS CHAR) LIKE '0000-00-00%';")"
if [[ "$type" != "date:YES" || "$engine" != "InnoDB" || "$rows" != "122" ]]; then
 echo "BLOQUEADO: esperado DATE NULL, InnoDB e 122 clientes; actual tipo=$type, motor=$engine, total=$rows." >&2
 exit 1
fi
if [[ "$invalid" == "0" ]]; then
 echo "Não existem datas zero. Nenhuma alteração necessária."; exit 0
fi
if [[ "$invalid" != "63" ]]; then
 echo "BLOQUEADO: diagnóstico anterior indicava 63 datas inválidas, agora existem $invalid." >&2
 echo "Reveja a situação antes de modificar dados." >&2
 exit 1
fi
echo "Verificado: $rows clientes; $invalid datas zero em Data de Emissão."
echo "Serão substituídas APENAS estas 63 datas zero por NULL. As datas válidas serão preservadas."
read -r -p "Confirma a normalização aprovada? Escreva NORMALIZAR: " confirm
[[ "$confirm" == "NORMALIZAR" ]] || { echo "Cancelado sem alterações."; exit 0; }
# Uma única sessão MySQL para transacção real, com verificação interna e ROLLBACK
# automático em caso de erro SQL. Sem ALTER TABLE, sem alterações a outros campos.
sql_tmp="$(mktemp "${TMPDIR:-/tmp}/cis-issue-dates.XXXXXXXX.sql")"
trap 'rm -f "$sql_tmp"' EXIT
chmod 600 "$sql_tmp"
cat > "$sql_tmp" <<'SQL'
SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
START TRANSACTION;
UPDATE `Clientes`
 SET `Data de Emissão` = NULL
 WHERE CAST(`Data de Emissão` AS CHAR) LIKE '0000-00-00%';
SELECT CONCAT('Linhas normalizadas: ',ROW_COUNT());
COMMIT;
SQL
"${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 -N -B "$MYSQL_DATABASE"' < "$sql_tmp"
after_rows="$(mysql_read 'SELECT COUNT(*) FROM `Clientes`;')"
after_invalid="$(mysql_read "SELECT COUNT(*) FROM \`Clientes\` WHERE CAST(\`Data de Emissão\` AS CHAR) LIKE '0000-00-00%';")"
if [[ "$after_rows" != "$rows" || "$after_invalid" != "0" ]]; then
 echo "ALERTA: verificação posterior divergente (clientes=$after_rows, datas zero=$after_invalid)." >&2
 exit 2
fi
echo "SUCESSO: $after_rows clientes preservados; nenhuma data zero remanescente em Data de Emissão."
echo "Os outros campos temporais ainda NÃO foram convertidos."
