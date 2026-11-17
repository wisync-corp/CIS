#!/usr/bin/env bash
# CAPTA: activar estornos somente depois do ensaio isolado e confirmacao.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || exit 1
sudo -v
exec 9>/tmp/cis-cash-reversal-activation.lock
flock -n 9 || { echo 'BLOQUEADO: outra activacao em curso.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 35 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'; }
check(){ local v; v="$(sql "$1")"; [[ "$v" == "$2" ]] || { echo "BLOQUEADO: $3 (actual $v)." >&2; exit 1; }; }
flag(){ [[ "$(grep -Ec "^${1}=" deploy/.env || true)" == 1 ]] && grep -Fxq "${1}=${2}" deploy/.env || { echo "BLOQUEADO: flag $1 deve ser $2." >&2; exit 1; }; }
echo '=== CAPTA | ACTIVAR ESTORNOS REAIS ==='
flag CIS_CASH_WRITES_APPROVED true
flag CIS_CASH_LEDGER_POSTING_APPROVED true
if grep -q '^CIS_CASH_REVERSALS_APPROVED=' deploy/.env; then flag CIS_CASH_REVERSALS_APPROVED false; fi
proof="$HOME/cis-private/cash-verification/reversal-rehearsal.sha256"
[[ -s "$proof" ]] || { echo 'BLOQUEADO: falta o ensaio isolado aprovado.' >&2; exit 1; }
sha="$(sha256sum backend/app/Http/Controllers/Api/CashReversalController.php | awk '{print $1}')"
[[ "$sha" == "$(cat "$proof")" ]] || { echo 'BLOQUEADO: codigo difere do ensaio.' >&2; exit 1; }
check 'SELECT DATABASE();' cis 'base inesperada'
check "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND ENGINE='InnoDB';" 1 'Extracto InnoDB'
check "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts' AND ENGINE='InnoDB';" 1 'Contas InnoDB'
check "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='operation_key' AND IS_NULLABLE='YES';" 1 'operation_key'
check "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND INDEX_NAME='cis_extracto_operation_key_uq' AND NON_UNIQUE=0;" 1 'indice UNIQUE'
check "SELECT COUNT(*) FROM Extracto e LEFT JOIN cis_cash_accounts a ON e.account_id=a.id WHERE e.account_id IS NOT NULL AND a.id IS NULL;" 0 'movimentos orfaos'
check "SELECT COUNT(*) FROM (SELECT operation_key FROM Extracto WHERE operation_key IS NOT NULL GROUP BY operation_key HAVING COUNT(*)>1) x;" 0 'duplicacoes'
bash deploy/verify-cash-ledger-writer.sh
"${APP[@]}" build api-preview
image_sha="$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview /srv/cis/app/Http/Controllers/Api/CashReversalController.php | awk '{print $1}')"
[[ "$sha" == "$image_sha" ]] || { echo 'Imagem da API desactualizada.' >&2; exit 1; }
echo 'O estorno cria lancamento inverso; nao executa devolucao bancaria. Contratos excluidos.'
read -r -p 'Confirma activar estornos reais? Digite ACTIVAR-ESTORNOS-REAIS: ' answer
[[ "$answer" == 'ACTIVAR-ESTORNOS-REAIS' ]] || { echo 'Cancelado.'; exit 0; }
bash deploy/backup-before-auth.sh
mkdir -p "$HOME/cis-private/cash-verification"
cp -p deploy/.env "$HOME/cis-private/cash-verification/deploy-env-before-reversal-activation.backup"
python3 -c 'import pathlib; p=pathlib.Path("deploy/.env"); x=[line for line in p.read_text().splitlines() if not line.startswith("CIS_CASH_REVERSALS_APPROVED=")]; x.append("CIS_CASH_REVERSALS_APPROVED=true"); p.write_text("\n".join(x)+"\n"); p.chmod(0o600)'
if ! "${APP[@]}" up -d --no-deps --force-recreate api-preview; then
  echo 'Reinicio falhou. Desactivando estornos.' >&2
  bash deploy/disable-cash-reversals.sh || true
  exit 1
fi
if ! "${APP[@]}" exec -T api-preview sh -c 'test "$CIS_CASH_REVERSALS_APPROVED" = true'; then
  echo 'Flag nao chegou ao container. Desactivando estornos.' >&2
  bash deploy/disable-cash-reversals.sh || true
  exit 1
fi
echo 'ESTORNOS REAIS ACTIVADOS; nenhum lancamento financeiro efectuado.'
