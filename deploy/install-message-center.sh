#!/usr/bin/env bash
# Instala as tabelas do CENTRO DE MENSAGENS (cis_message_templates, cis_message_log).
# Tabelas novas do CIS; não altera Contractos, Extracto, Clientes nem a Caixa.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-message-center-install.lock
flock -n 9 || { echo 'BLOQUEADO: instalação concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
migration=backend/database/migrations/2026_11_19_000080_create_cis_message_center.php
echo '=== CIS | INSTALAR CENTRO DE MENSAGENS ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || { echo 'BLOQUEADO: base inesperada.' >&2; exit 1; }
have="$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('cis_message_templates','cis_message_log');")"
if [[ "$have" == 2 ]]; then echo 'Centro de mensagens já instalado.'; sql 'SELECT COUNT(*) AS modelos FROM cis_message_templates;'; exit 0; fi
"${APP[@]}" build api-preview
code_sha="$(sha256sum "$migration" | cut -d' ' -f1)"
image_sha="$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview /srv/cis/${migration#backend/} | cut -d' ' -f1)"
[[ "$code_sha" == "$image_sha" ]] || { echo 'BLOQUEADO: imagem da API desactualizada (faça git pull).' >&2; exit 1; }
"${APP[@]}" run --rm --no-deps api-preview php artisan migrate --path="${migration#backend/}" --force
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('cis_message_templates','cis_message_log');")" == 2 ]] || {
  echo 'BLOQUEADO: tabelas não confirmadas.' >&2; exit 1; }
echo "CENTRO DE MENSAGENS INSTALADO: $(sql 'SELECT COUNT(*) FROM cis_message_templates;') modelo(s) iniciais."
echo 'Próximo: bash deploy/refresh-private-preview.sh e Ctrl+F5 no navegador.'
