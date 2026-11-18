#!/usr/bin/env bash
# CAPTA: instala o HISTÓRICO IMUTÁVEL das prestações (cis_contractos_historico).
# Aditivo: não altera Contractos, Extracto nem contas; não activa pagamentos.
# Cria triggers MySQL que recusam UPDATE e DELETE no histórico (só INSERT).
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-contract-history-install.lock
flock -n 9 || { echo 'BLOQUEADO: instalação concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
migration=backend/database/migrations/2026_11_18_000070_create_cis_contractos_historico.php
echo '=== CAPTA | INSTALAR HISTÓRICO IMUTÁVEL DAS PRESTAÇÕES ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || exit 1
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_contractos_historico';")" == 0 ]] || {
  echo 'O histórico já está instalado. Verificação: bash deploy/verify-contract-cash-flows.sh'; exit 0; }
for t in Contractos Extracto; do
  [[ "$(sql "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t';")" == InnoDB ]] || {
    echo "BLOQUEADO: $t não é InnoDB (sem rollback garantido)." >&2; exit 1; }
done
financial="$(sql 'SELECT COUNT(*),COALESCE(SUM(Valor),0),COALESCE(MAX(Nr),0) FROM Extracto;')"
contracts="$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor a Pagar`),0) FROM Contractos;')"
"${APP[@]}" build api-preview
code_sha="$(sha256sum "$migration" | cut -d' ' -f1)"
image_sha="$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview /srv/cis/${migration#backend/} | cut -d' ' -f1)"
[[ "$code_sha" == "$image_sha" ]] || { echo 'BLOQUEADO: imagem da API desactualizada.' >&2; exit 1; }
echo 'Vai criar a tabela cis_contractos_historico e os triggers que impedem alterar/apagar o histórico.'
read -r -p 'Digite INSTALAR-HISTORICO-PRESTACOES: ' answer
[[ "$answer" == INSTALAR-HISTORICO-PRESTACOES ]] || { echo 'Cancelado.'; exit 0; }
bash deploy/backup-before-auth.sh
"${APP[@]}" run --rm --no-deps api-preview php artisan migrate --path="${migration#backend/}" --force
sql "CREATE TRIGGER cis_contr_hist_no_update BEFORE UPDATE ON cis_contractos_historico FOR EACH ROW
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historico de prestacoes e imutavel: UPDATE recusado';
CREATE TRIGGER cis_contr_hist_no_delete BEFORE DELETE ON cis_contractos_historico FOR EACH ROW
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historico de prestacoes e imutavel: DELETE recusado';"
# Leitura do histórico pelo utilizador de consulta (só SELECT), se existir.
reader="$(grep -E '^LEGACY_DB_USERNAME=' deploy/.env | cut -d= -f2- || true)"; reader="${reader:-cis_reader}"
if [[ "$reader" =~ ^[A-Za-z0-9_]{1,32}$ && "$(sql "SELECT COUNT(*) FROM mysql.user WHERE User='$reader' AND Host='%';")" == 1 ]]; then
  sql "GRANT SELECT ON \`cis\`.\`cis_contractos_historico\` TO '$reader'@'%';"
fi
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='cis_contractos_historico' AND ACTION_TIMING='BEFORE' AND EVENT_MANIPULATION IN ('UPDATE','DELETE') AND ACTION_STATEMENT LIKE '%SIGNAL SQLSTATE%';")" == 2 ]] || {
  echo 'BLOQUEADO: triggers de imutabilidade não confirmados.' >&2; exit 1; }
# Prova real de imutabilidade numa transacção revertida (nada fica gravado).
probe="$(printf '%s\n' "START TRANSACTION;
INSERT INTO cis_contractos_historico(contract_number,operation,operation_key,performed_by,performed_at) VALUES ('PROBE','probe','probe:install',0,NOW());
UPDATE cis_contractos_historico SET reason='x' WHERE operation_key='probe:install';" \
 | timeout 30 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch "$MYSQL_DATABASE"' 2>&1 || true)"
[[ "$probe" == *'imutavel'* ]] || { echo 'BLOQUEADO: UPDATE no histórico não foi recusado.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM cis_contractos_historico;")" == 0 ]] || { echo 'BLOQUEADO: registo de prova persistiu.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(Valor),0),COALESCE(MAX(Nr),0) FROM Extracto;')" == "$financial" ]] || { echo 'ATENÇÃO: Extracto alterado durante a instalação.' >&2; exit 1; }
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(`Valor a Pagar`),0) FROM Contractos;')" == "$contracts" ]] || { echo 'ATENÇÃO: Contractos alterados durante a instalação.' >&2; exit 1; }
echo 'HISTÓRICO DE PRESTAÇÕES INSTALADO (só INSERT; UPDATE e DELETE recusados pela base de dados).'
echo 'Próximo: bash deploy/activate-contract-cash-flows.sh'
