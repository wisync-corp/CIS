#!/usr/bin/env bash
# Instala os MUTUÁRIOS ao nível do GEMIC: pessoa colectiva (empresa), fotografia, lista negra e avalista já registado.
# Só tabelas do CIS; não altera Clientes, Contractos nem Extracto.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-client-registry-install.lock
flock -n 9 || { echo 'BLOQUEADO: instalação concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
migs=(backend/database/migrations/2026_11_26_000250_cis_client_registry.php backend/database/migrations/2026_11_26_000260_cis_blacklist_origin.php)
echo '=== CIS | INSTALAR MUTUÁRIOS (EMPRESAS, FOTOGRAFIA, LISTA NEGRA) ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || { echo 'BLOQUEADO: base inesperada.' >&2; exit 1; }
"${APP[@]}" build api-preview
for mig in "${migs[@]}"; do
  code_sha="$(sha256sum "$mig" | cut -d' ' -f1)"
  image_sha="$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview /srv/cis/${mig#backend/} | cut -d' ' -f1)"
  [[ "$code_sha" == "$image_sha" ]] || { echo 'BLOQUEADO: imagem da API desactualizada. Faça git pull.' >&2; exit 1; }
  "${APP[@]}" run --rm --no-deps api-preview php artisan migrate --path="${mig#backend/}" --force
done
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('cis_client_profile','cis_client_blacklist');")" == 2 ]] || {
  echo 'BLOQUEADO: tabelas do perfil e da lista negra não confirmadas.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_documents';")" == 1 ]] || {
  echo 'AVISO: a fotografia e os documentos precisam do dossier: bash deploy/install-credit-dossier.sh' >&2; }
echo "MUTUÁRIOS: $(sql "SELECT COUNT(*) FROM cis_client_profile WHERE tipo='colectiva';") empresa(s), $(sql 'SELECT COUNT(DISTINCT client_id) FROM cis_client_blacklist WHERE removed_at IS NULL;') na lista negra."
echo 'Lista negra: Mutuários › Lista negra; manual na ficha (Administração › Conduta) ou automática ao entrar (Definições › Cobrança › Incumprimento prolongado, 90 dias por omissão).'
echo 'Próximo: bash deploy/refresh-private-preview.sh e Ctrl+F5 no navegador.'
