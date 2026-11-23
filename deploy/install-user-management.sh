#!/usr/bin/env bash
# Instala a GESTÃO DE UTILIZADORES (funções, palavra-passe temporária, último acesso, auditoria)
# e as AGÊNCIAS (cis_branches + correspondência de contratos e contas da Caixa).
# Só tabelas/colunas do CIS (users e cis_*); não altera Contractos, Extracto, Clientes nem a Caixa.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-user-management-install.lock
flock -n 9 || { echo 'BLOQUEADO: instalação concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
users=backend/database/migrations/2026_11_23_000170_cis_user_management.php
branches=backend/database/migrations/2026_11_23_000180_cis_branches.php
echo '=== CIS | INSTALAR UTILIZADORES E AGÊNCIAS ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || { echo 'BLOQUEADO: base inesperada.' >&2; exit 1; }
"${APP[@]}" build api-preview
for f in "$users" "$branches"; do
  code_sha="$(sha256sum "$f" | cut -d' ' -f1)"
  image_sha="$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview /srv/cis/${f#backend/} | cut -d' ' -f1)"
  [[ "$code_sha" == "$image_sha" ]] || { echo "BLOQUEADO: imagem da API desactualizada ($f). Faça git pull." >&2; exit 1; }
done
"${APP[@]}" run --rm --no-deps api-preview php artisan migrate --path="${users#backend/}" --force
"${APP[@]}" run --rm --no-deps api-preview php artisan migrate --path="${branches#backend/}" --force
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME IN ('last_login_at','must_change_password','branch_id');")" == 3 ]] || {
  echo 'BLOQUEADO: colunas de utilizadores não confirmadas.' >&2; exit 1; }
echo "UTILIZADORES: $(sql "SELECT COUNT(*) FROM users WHERE active=1;") activo(s)."
echo "AGÊNCIAS: $(sql 'SELECT GROUP_CONCAT(CONCAT(codigo," · ",nome) SEPARATOR ", ") FROM cis_branches;')"
echo 'Próximo: bash deploy/refresh-private-preview.sh e Ctrl+F5 no navegador.'
