#!/usr/bin/env bash
# Instala as CONTACTOS DE COBRANÇA, PROMESSAS DE PAGAMENTO e o modelo «Lembrete de promessa».
# Só tabelas do CIS; não altera Contractos, Extracto, Clientes nem a Caixa.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-collection-contacts-install.lock
flock -n 9 || { echo 'BLOQUEADO: instalação concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
mig=backend/database/migrations/2026_11_23_000220_cis_collection_contacts.php
echo '=== CIS | INSTALAR CONTACTOS E PROMESSAS ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || { echo 'BLOQUEADO: base inesperada.' >&2; exit 1; }
"${APP[@]}" build api-preview
code_sha="$(sha256sum "$mig" | cut -d' ' -f1)"
image_sha="$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview /srv/cis/${mig#backend/} | cut -d' ' -f1)"
[[ "$code_sha" == "$image_sha" ]] || { echo 'BLOQUEADO: imagem da API desactualizada. Faça git pull.' >&2; exit 1; }
"${APP[@]}" run --rm --no-deps api-preview php artisan migrate --path="${mig#backend/}" --force
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_collection_contacts';")" == 1 ]] || {
  echo 'BLOQUEADO: tabela de contactos não confirmada.' >&2; exit 1; }
echo "CONTACTOS: $(sql 'SELECT COUNT(*) FROM cis_collection_contacts;') registado(s); modelo de promessa: $(sql "SELECT COUNT(*) FROM cis_message_templates WHERE name='Lembrete de promessa';")."
echo 'Regras dos lembretes: Definições › Cobrança e multa.'
echo 'Próximo: bash deploy/refresh-private-preview.sh e Ctrl+F5 no navegador.'
