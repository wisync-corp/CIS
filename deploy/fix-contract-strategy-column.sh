#!/usr/bin/env bash
# Migração controlada do identificador com dupla codificação em Contractos.
# Apenas renomeia a coluna: não modifica os 679 contratos nem o Extracto.
# Executar na VPS e apenas com escritores concorrentes desligados.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: falta deploy/.env' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql() {
  printf '%s\n' "$1" | "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'
}
fail() { echo "BLOQUEADO: $*" >&2; exit 1; }
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || fail 'base activa não é cis.'
old_hex='457374726174C383C2A96769612064652043C383C2A16C63756C6F'
new_hex='457374726174C3A96769612064652043C3A16C63756C6F'
old_count="$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos' AND HEX(COLUMN_NAME)='$old_hex';")" || fail 'consulta do nome antigo falhou.'
new_count="$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos' AND HEX(COLUMN_NAME)='$new_hex';")" || fail 'consulta do nome correcto falhou.'
if [[ "$old_count" == 0 && "$new_count" == 1 ]]; then
  echo 'OK: a coluna Estratégia de Cálculo já está correctamente nomeada. Nenhuma alteração necessária.'
  exit 0
fi
[[ "$old_count" == 1 && "$new_count" == 0 ]] || fail "esquema inesperado (antiga=$old_count, correcta=$new_count)."
before_contracts="$(sql 'SELECT COUNT(*) FROM Contractos;')" || fail 'contagem Contractos falhou.'
before_extract="$(sql 'SELECT COUNT(*) FROM Extracto;')" || fail 'contagem Extracto falhou.'
echo "Contractos antes: $before_contracts | Extracto antes: $before_extract"
echo 'Operação proposta: renomear APENAS a coluna existente EstratÃ©gia de CÃ¡lculo -> Estratégia de Cálculo.'
echo 'IMPORTANTE: parar o Excel antigo e outros serviços que escrevem na base antes de confirmar.'
read -r -p 'Para criar backup e renomear a coluna, escreva CORRIGIR-COLUNA: ' approval
[[ "$approval" == 'CORRIGIR-COLUNA' ]] || { echo 'Cancelado. Nenhuma alteração efectuada.'; exit 0; }
echo 'A criar backup integral protegido...'
bash deploy/backup-before-auth.sh
echo 'A executar RENAME COLUMN (DDL; não altera linhas).'
sql "
SET @legacy_name=(SELECT COLUMN_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos'
 AND HEX(COLUMN_NAME)='$old_hex');
SET @rename_sql=CONCAT('ALTER TABLE \`Contractos\` RENAME COLUMN \`',
 REPLACE(@legacy_name, '\`', '\`\`'), '\` TO \`Estratégia de Cálculo\`');
PREPARE cis_rename FROM @rename_sql;
EXECUTE cis_rename;
DEALLOCATE PREPARE cis_rename;
" || fail 'falha na operação DDL; verificar esquema e backup antes de tentar novamente.'
after_old="$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos' AND HEX(COLUMN_NAME)='$old_hex';")"
after_new="$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos' AND HEX(COLUMN_NAME)='$new_hex';")"
after_contracts="$(sql 'SELECT COUNT(*) FROM Contractos;')"
after_extract="$(sql 'SELECT COUNT(*) FROM Extracto;')"
[[ "$after_old" == 0 && "$after_new" == 1 ]] || fail 'verificação da coluna falhou após operação.'
[[ "$before_contracts" == "$after_contracts" && "$before_extract" == "$after_extract" ]] || fail 'contagens mudaram; verificar escritor concorrente e investigar.'
echo "SUCESSO: coluna corrigida. Contractos=$after_contracts, Extracto=$after_extract."
echo 'Não activa fluxos financeiros; executar testes e activação separadamente.'
