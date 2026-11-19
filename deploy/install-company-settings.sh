#!/usr/bin/env bash
# Instala as CONFIGURAÇÕES DA EMPRESA (cis_company_settings) e a PARAMETRIZAÇÃO editável
# (grupo/activo/protegido em cis_tipos_movimento e cis_parametrizacao_log).
# Tabela nova do CIS; não altera Contractos, Extracto, Clientes nem a Caixa.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-company-settings-install.lock
flock -n 9 || { echo 'BLOQUEADO: instalação concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
migration=backend/database/migrations/2026_11_19_000110_create_cis_company_settings.php
param=backend/database/migrations/2026_11_19_000120_parametrizacao_editavel.php
echo '=== CIS | INSTALAR CONFIGURAÇÕES DA EMPRESA ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || { echo 'BLOQUEADO: base inesperada.' >&2; exit 1; }
"${APP[@]}" build api-preview
for f in "$migration" "$param"; do
  code_sha="$(sha256sum "$f" | cut -d' ' -f1)"
  image_sha="$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview /srv/cis/${f#backend/} | cut -d' ' -f1)"
  [[ "$code_sha" == "$image_sha" ]] || { echo "BLOQUEADO: imagem da API desactualizada ($f). Faça git pull." >&2; exit 1; }
done
"${APP[@]}" run --rm --no-deps api-preview php artisan migrate --path="${migration#backend/}" --force
# Parametrização editável (idempotente: só acrescenta colunas/tabela em falta).
"${APP[@]}" run --rm --no-deps api-preview php artisan migrate --path="${param#backend/}" --force
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_company_settings';")" == 1 ]] || {
  echo 'BLOQUEADO: tabela não confirmada.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_tipos_movimento' AND COLUMN_NAME IN ('grupo','activo','protegido');")" == 3 ]] || {
  echo 'BLOQUEADO: colunas da parametrização não confirmadas.' >&2; exit 1; }
echo "PARAMETRIZAÇÃO EDITÁVEL: $(sql 'SELECT COUNT(*) FROM cis_tabela_juros;') linha(s) de preços, $(sql 'SELECT COUNT(*) FROM cis_tipos_movimento;') tipo(s) de movimento."
echo "CONFIGURAÇÕES INSTALADAS: $(sql 'SELECT COUNT(*) FROM cis_company_settings;') valor(es) gravado(s)."
echo 'Próximo: bash deploy/refresh-private-preview.sh e Ctrl+F5 no navegador.'
