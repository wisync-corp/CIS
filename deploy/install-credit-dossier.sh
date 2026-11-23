#!/usr/bin/env bash
# Instala o DOSSIER DO CRÉDITO: fiadores, garantias, documentos digitalizados e agência de cada mutuário.
# Só tabelas do CIS (os ficheiros ficam na base, dentro da cópia de segurança); não altera Clientes, Contractos nem Extracto.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-credit-dossier-install.lock
flock -n 9 || { echo 'BLOQUEADO: instalação concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
mig=backend/database/migrations/2026_11_23_000240_cis_credit_dossier.php
echo '=== CIS | INSTALAR DOSSIER DO CRÉDITO ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || { echo 'BLOQUEADO: base inesperada.' >&2; exit 1; }
"${APP[@]}" build api-preview
code_sha="$(sha256sum "$mig" | cut -d' ' -f1)"
image_sha="$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview /srv/cis/${mig#backend/} | cut -d' ' -f1)"
[[ "$code_sha" == "$image_sha" ]] || { echo 'BLOQUEADO: imagem da API desactualizada. Faça git pull.' >&2; exit 1; }
"${APP[@]}" run --rm --no-deps api-preview php artisan migrate --path="${mig#backend/}" --force
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('cis_guarantors','cis_collaterals','cis_documents','cis_document_files','cis_client_branch');")" == 5 ]] || {
  echo 'BLOQUEADO: tabelas do dossier não confirmadas.' >&2; exit 1; }
[[ "$(sql "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_document_files' AND COLUMN_NAME='conteudo';")" == mediumblob ]] || {
  echo 'BLOQUEADO: coluna dos ficheiros não é MEDIUMBLOB.' >&2; exit 1; }
echo "DOSSIER: $(sql 'SELECT COUNT(*) FROM cis_documents WHERE deleted_at IS NULL;') documento(s), $(sql 'SELECT COUNT(*) FROM cis_guarantors;') fiador(es), $(sql 'SELECT COUNT(*) FROM cis_collaterals;') garantia(s)."
echo 'O que a aprovação exige (BI, residência, rendimento, fiador, cobertura): Definições › Credit scoring › Dossier do crédito.'
echo 'Por omissão só o BI é exigido para aprovar uma proposta.'
echo 'Próximo: bash deploy/refresh-private-preview.sh e Ctrl+F5 no navegador.'
