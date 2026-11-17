#!/usr/bin/env bash
# CAPTA: activar a GRAVAÇÃO de fechos diários de controlo (Caixa Geral Padrão).
# Pré-requisitos: ensaio isolado aprovado, tabela instalada e escritor restrito verificado.
# Não lança movimentos, não altera saldos, não bloqueia períodos nem a Tesouraria.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-cash-close-activation.lock
flock -n 9 || { echo 'BLOQUEADO: outra activação em curso.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 35 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'; }
check(){ local v; v="$(sql "$1")"; [[ "$v" == "$2" ]] || { echo "BLOQUEADO: $3 (actual $v)." >&2; exit 1; }; }
echo '=== CAPTA | ACTIVAR GRAVAÇÃO DE FECHOS DIÁRIOS ==='
[[ "$(grep -Ec '^CIS_CASH_DAILY_CLOSE_APPROVED=' deploy/.env || true)" == 1 ]] \
  && grep -Fxq 'CIS_CASH_DAILY_CLOSE_APPROVED=false' deploy/.env \
  || { echo 'BLOQUEADO: execute antes prepare-cash-daily-closure-writer.sh (flag deve existir e estar false).' >&2; exit 1; }
proof="$HOME/cis-private/cash-verification/daily-closure-migration.sha256"
migration=backend/database/migrations/2026_11_17_000060_create_cis_cash_daily_closures.php
[[ -s "$proof" ]] || { echo 'BLOQUEADO: falta o ensaio isolado aprovado.' >&2; exit 1; }
[[ "$(sha256sum "$migration" | awk '{print $1}')" == "$(cat "$proof")" ]] || { echo 'BLOQUEADO: migração difere do ensaio.' >&2; exit 1; }
check 'SELECT DATABASE();' cis 'base inesperada'
check "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_daily_closures' AND ENGINE='InnoDB';" 1 'tabela de fechos InnoDB'
check "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_daily_closures' AND INDEX_NAME='cis_cash_close_account_date_uq' AND NON_UNIQUE=0;" 2 'UNIQUE conta/data'
check "SELECT COUNT(*) FROM migrations WHERE migration='2026_11_17_000060_create_cis_cash_daily_closures';" 1 'migração registada'
check "SELECT COUNT(*) FROM cis_cash_daily_closures c LEFT JOIN cis_cash_accounts a ON a.id=c.account_id WHERE a.id IS NULL;" 0 'fechos órfãos'
active="$(sql "SELECT COUNT(*) FROM cis_cash_accounts WHERE status='activa' AND opening_extracto_nr IS NOT NULL AND validated_at IS NOT NULL;")"
[[ "$active" -ge 1 ]] || { echo 'BLOQUEADO: não há contas activas com abertura validada para fechar.' >&2; exit 1; }
bash deploy/verify-cash-daily-closure-writer.sh
"${APP[@]}" build api-preview
code_sha="$(sha256sum backend/app/Http/Controllers/Api/CashClosureController.php | awk '{print $1}')"
image_sha="$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview /srv/cis/app/Http/Controllers/Api/CashClosureController.php | awk '{print $1}')"
[[ "$code_sha" == "$image_sha" ]] || { echo 'BLOQUEADO: imagem da API desactualizada.' >&2; exit 1; }
financial="$(sql 'SELECT COUNT(*),COALESCE(SUM(Valor),0),COALESCE(MAX(Nr),0) FROM Extracto;')"
accounts="$(sql 'SELECT COUNT(*),COALESCE(SUM(opening_balance),0) FROM cis_cash_accounts;')"
echo "Contas activas que poderão ser fechadas: $active"
echo 'O fecho regista a conferência (saldo observado, diferença, comprovativo). Não cria movimentos,'
echo 'não ajusta saldos, não bloqueia outras contas e não substitui a reconciliação da Tesouraria.'
echo 'Cada fecho é imutável: um por conta e por dia, sem edição nem anulação.'
read -r -p 'Confirma activar a gravação de fechos? Digite ACTIVAR-FECHO-CAIXA: ' answer
[[ "$answer" == 'ACTIVAR-FECHO-CAIXA' ]] || { echo 'Cancelado.'; exit 0; }
bash deploy/backup-before-auth.sh
mkdir -p "$HOME/cis-private/cash-verification"
cp -p deploy/.env "$HOME/cis-private/cash-verification/deploy-env-before-daily-close-activation.backup"
python3 -c 'import pathlib,os,tempfile; p=pathlib.Path("deploy/.env"); x=[v for v in p.read_text().splitlines() if not v.startswith("CIS_CASH_DAILY_CLOSE_APPROVED=")]; x.append("CIS_CASH_DAILY_CLOSE_APPROVED=true"); fd,n=tempfile.mkstemp(prefix=".cash-close-activate-",dir=str(p.parent)); os.fchmod(fd,0o600); f=os.fdopen(fd,"w"); f.write("\n".join(x)+"\n"); f.flush(); os.fsync(f.fileno()); f.close(); os.replace(n,p)'
if ! "${APP[@]}" up -d --no-deps --force-recreate api-preview; then
  echo 'Reinício falhou. A desactivar fechos.' >&2
  bash deploy/disable-cash-daily-closures.sh || true
  exit 1
fi
if ! "${APP[@]}" exec -T api-preview sh -c 'test "$CIS_CASH_DAILY_CLOSE_APPROVED" = true'; then
  echo 'Flag não chegou ao container. A desactivar fechos.' >&2
  bash deploy/disable-cash-daily-closures.sh || true
  exit 1
fi
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(Valor),0),COALESCE(MAX(Nr),0) FROM Extracto;')" == "$financial" ]] || echo 'AVISO: o Extracto mudou durante a activação (movimentos legítimos em paralelo?). Verifique.' >&2
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(opening_balance),0) FROM cis_cash_accounts;')" == "$accounts" ]] || { echo 'BLOQUEADO: contas alteradas. A desactivar fechos.' >&2; bash deploy/disable-cash-daily-closures.sh || true; exit 1; }
echo 'GRAVAÇÃO DE FECHOS ACTIVADA. Nenhum fecho foi gravado; faça o primeiro pela interface.'
echo 'Para desligar a qualquer momento: bash deploy/disable-cash-daily-closures.sh'
