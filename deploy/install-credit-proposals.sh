#!/usr/bin/env bash
# Instala as PROPOSTAS DE CRÉDITO COM APROVAÇÃO (cis_credit_proposals + cis_credit_proposal_events).
# Só tabelas do CIS; não altera Contractos, Extracto, Clientes nem a Caixa.
# ATENÇÃO: depois de instalado, só se abrem contratos a partir de propostas aprovadas.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-credit-proposals-install.lock
flock -n 9 || { echo 'BLOQUEADO: instalação concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
migs=(backend/database/migrations/2026_11_23_000210_cis_credit_proposals.php backend/database/migrations/2026_11_23_000230_cis_proposal_rules.php)
echo '=== CIS | INSTALAR PROPOSTAS DE CRÉDITO ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || { echo 'BLOQUEADO: base inesperada.' >&2; exit 1; }
"${APP[@]}" build api-preview
for mig in "${migs[@]}"; do
  code_sha="$(sha256sum "$mig" | cut -d' ' -f1)"
  image_sha="$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview /srv/cis/${mig#backend/} | cut -d' ' -f1)"
  [[ "$code_sha" == "$image_sha" ]] || { echo 'BLOQUEADO: imagem da API desactualizada. Faça git pull.' >&2; exit 1; }
  "${APP[@]}" run --rm --no-deps api-preview php artisan migrate --path="${mig#backend/}" --force
done
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('cis_credit_proposals','cis_credit_proposal_events');")" == 2 ]] || {
  echo 'BLOQUEADO: tabelas das propostas não confirmadas.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='cis_branches' AND COLUMN_NAME IN ('credito_minimo','credito_maximo','taxa_minima','taxa_maxima')) OR (TABLE_NAME='cis_credit_proposals' AND COLUMN_NAME IN ('avisado_estado','avisado_em')));")" == 6 ]] || {
  echo 'BLOQUEADO: limites das agências / avisos não confirmados.' >&2; exit 1; }
echo "PROPOSTAS: $(sql 'SELECT COUNT(*) FROM cis_credit_proposals;') registada(s)."
echo 'A partir de agora os contratos só se abrem a partir de propostas aprovadas.'
echo 'Limite de aprovação do gestor e validade: Definições › Credit scoring.'
echo 'Limites de montante e taxa por agência: Definições › Agências › Editar.'
echo 'Avisos ao mutuário (aprovada/recusada/não levantada): Mensagens › Avisos de propostas.'
echo 'Próximo: bash deploy/refresh-private-preview.sh e Ctrl+F5 no navegador.'
