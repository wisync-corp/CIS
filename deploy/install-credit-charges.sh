#!/usr/bin/env bash
# Instala os ENCARGOS DO CRÉDITO: categoria de finalidade nas propostas (as taxas, a mora automática e as regras
# da renovação são definições da empresa, em Definições › Encargos do crédito e › Cobrança e juros de mora).
# Só acrescenta uma coluna a cis_credit_proposals; não altera Contractos, Extracto nem Caixa.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
mig=backend/database/migrations/2026_11_26_000280_cis_credit_charges.php
echo '=== CIS | INSTALAR ENCARGOS DO CRÉDITO ==='
"${APP[@]}" build api-preview
code_sha="$(sha256sum "$mig" | cut -d' ' -f1)"
image_sha="$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview /srv/cis/${mig#backend/} | cut -d' ' -f1)"
[[ "$code_sha" == "$image_sha" ]] || { echo 'BLOQUEADO: imagem da API desactualizada. Faça git pull.' >&2; exit 1; }
"${APP[@]}" run --rm --no-deps api-preview php artisan migrate --path="${mig#backend/}" --force
echo 'Instalado. Configure em Definições › Encargos do crédito (taxa de operação, renovação, finalidades)'
echo 'e em Definições › Cobrança e juros de mora (mora automática: taxa diária, tolerância, base, tecto).'
echo 'Próximo: bash deploy/refresh-private-preview.sh e Ctrl+F5 no navegador.'
