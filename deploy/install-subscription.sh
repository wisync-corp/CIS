#!/usr/bin/env bash
# Instala a SUBSCRIÇÃO do CIS: planos, limite de utilizadores, renovação e pagamentos M-Pesa / e-Mola.
# Só cria tabelas do CIS (cis_subscription, cis_subscription_payments); não toca na Caixa, Contractos nem Extracto.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-subscription-install.lock
flock -n 9 || { echo 'BLOQUEADO: instalação concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
mig=backend/database/migrations/2026_11_26_000270_cis_subscription.php
echo '=== CIS | INSTALAR SUBSCRIÇÃO (PLANOS, UTILIZADORES, M-PESA / E-MOLA) ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || { echo 'BLOQUEADO: base inesperada.' >&2; exit 1; }
"${APP[@]}" build api-preview
code_sha="$(sha256sum "$mig" | cut -d' ' -f1)"
image_sha="$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview /srv/cis/${mig#backend/} | cut -d' ' -f1)"
[[ "$code_sha" == "$image_sha" ]] || { echo 'BLOQUEADO: imagem da API desactualizada. Faça git pull.' >&2; exit 1; }
"${APP[@]}" run --rm --no-deps api-preview php artisan migrate --path="${mig#backend/}" --force
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('cis_subscription','cis_subscription_payments');")" == 2 ]] || {
  echo 'BLOQUEADO: tabelas da subscrição não confirmadas.' >&2; exit 1; }
"${APP[@]}" run --rm --no-deps api-preview php artisan cis:subscricao || true
grep -q '^MPESA_API_KEY=.\+' deploy/.env && echo 'M-Pesa: integração API configurada.' || echo 'M-Pesa: modo manual (defina CIS_MPESA_NUMERO; para a API: MPESA_API_KEY, MPESA_PUBLIC_KEY, MPESA_SERVICE_PROVIDER_CODE, MPESA_HOST).'
grep -q '^EMOLA_WSDL=.\+' deploy/.env && echo 'e-Mola: integração API configurada.' || echo 'e-Mola: modo manual (defina CIS_EMOLA_NUMERO; para a API: EMOLA_WSDL, EMOLA_USERNAME, EMOLA_PASSWORD, EMOLA_KEY, EMOLA_PARTNER_CODE, EMOLA_CALLBACK_SECRET).'
echo 'Confirmar um pagamento manual: sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml exec api-preview php artisan cis:subscricao REFERENCIA --confirmar --transacao=ID'
echo 'Próximo: bash deploy/refresh-private-preview.sh e Ctrl+F5 no navegador. Definições › Subscrição.'
