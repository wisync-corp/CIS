#!/usr/bin/env bash
# NUMERAÇÃO ANUAL DOS CONTRATOS: renumera TODOS os contratos para PREFIXO-ANO-SEQUÊNCIA
# (recomeça em 1 em cada ano, pela data de desembolso). Faz cópia de segurança antes.
# Uso: bash deploy/renumber-contracts.sh            → mostra o plano e pede confirmação
#      O prefixo e os dígitos vêm de Definições › Numeração dos contratos.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-renumber-contracts.lock
flock -n 9 || { echo 'BLOQUEADO: renumeração concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 120 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
migration=backend/database/migrations/2026_11_19_000140_create_cis_contract_numbers.php
echo '=== CIS | NUMERAÇÃO ANUAL DOS CONTRATOS ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || { echo 'BLOQUEADO: base inesperada.' >&2; exit 1; }
"${APP[@]}" build api-preview
for f in "$migration" backend/app/Services/ContractNumbering.php backend/app/Console/Commands/RenumberContracts.php; do
  code_sha="$(sha256sum "$f" | cut -d' ' -f1)"
  image_sha="$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview /srv/cis/${f#backend/} | cut -d' ' -f1)"
  [[ "$code_sha" == "$image_sha" ]] || { echo "BLOQUEADO: imagem da API desactualizada ($f). Faça git pull." >&2; exit 1; }
done
"${APP[@]}" run --rm --no-deps api-preview php artisan migrate --path="${migration#backend/}" --force

echo; echo '--- Plano (nada é gravado) ---'
"${APP[@]}" run --rm --no-deps api-preview php artisan cis:renumerar-contratos

echo; read -r -p 'Escreva RENUMERAR para fazer a cópia de segurança e gravar: ' answer
[[ "$answer" == RENUMERAR ]] || { echo 'Cancelado. Nada foi alterado.'; exit 0; }

mkdir -p backups
backup="backups/antes-renumeracao-$(date +%Y%m%d-%H%M%S).sql.gz"
tables="$(sql "SELECT GROUP_CONCAT(TABLE_NAME SEPARATOR ' ') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('Contractos','Extracto','cis_message_log','cis_contract_numbers','cis_company_settings');")"
timeout 600 "${DB[@]}" exec -T -e T="$tables" mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --quick --set-gtid-purged=OFF --skip-triggers --default-character-set=utf8mb4 "$MYSQL_DATABASE" $T' </dev/null | gzip > "$backup"
[[ -s "$backup" ]] || { echo 'BLOQUEADO: cópia de segurança vazia.' >&2; exit 1; }
echo "Cópia de segurança: $backup"

before_rows="$(sql 'SELECT COUNT(*) FROM Contractos;')"
before_ext="$(sql 'SELECT COUNT(*) FROM Extracto;')"
"${APP[@]}" run --rm --no-deps api-preview php artisan cis:renumerar-contratos --aplicar --confirmo=RENUMERAR --por="$(whoami)@vps"

[[ "$(sql 'SELECT COUNT(*) FROM Contractos;')" == "$before_rows" && "$(sql 'SELECT COUNT(*) FROM Extracto;')" == "$before_ext" ]] || {
  echo "ATENÇÃO: número de linhas mudou. A cópia está em $backup." >&2; exit 1; }
echo "Contratos por ano:"; sql "SELECT ano, COUNT(*) FROM cis_contract_numbers GROUP BY ano ORDER BY ano;" | sed 's/^/  /'
echo "Para repor (só se necessário): gunzip -c $backup | sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml exec -T mysql sh -c 'MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysql -uroot \"\$MYSQL_DATABASE\"'"
echo 'NUMERAÇÃO ANUAL ACTIVA. Próximo: bash deploy/refresh-private-preview.sh e Ctrl+F5.'
