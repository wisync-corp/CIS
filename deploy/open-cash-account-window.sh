#!/usr/bin/env bash
# CAPTA: janela controlada para abrir saldo inicial de contas novas quando as
# movimentações gerais já estão activas (CIS_CASH_WRITES_APPROVED=true).
#
# Durante a janela, entradas/saídas/transferências/estornos ficam SUSPENSOS para
# que o marcador opening_extracto_nr seja capturado sem movimentos em paralelo.
# No fim (ou em caso de erro / Ctrl+C / desligar SSH) a flag é reposta a true.
# Não escreve no Extracto; só a interface de abertura altera cis_cash_accounts.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-cash-opening-window.lock
flock -n 9 || { echo 'BLOQUEADO: outra janela de abertura em curso.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 35 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'; }
flag(){ [[ "$(grep -Ec "^${1}=" deploy/.env || true)" == 1 ]] && grep -Fxq "${1}=${2}" deploy/.env || { echo "BLOQUEADO: flag $1 deve ser $2." >&2; exit 1; }; }
setflag(){ python3 -c 'import pathlib,os,sys,tempfile; k,v=sys.argv[1],sys.argv[2]; p=pathlib.Path("deploy/.env"); x=[l for l in p.read_text().splitlines() if not l.startswith(k+"=")]; x.append(k+"="+v); fd,n=tempfile.mkstemp(prefix=".cash-window-",dir=str(p.parent)); os.fchmod(fd,0o600); f=os.fdopen(fd,"w"); f.write("\n".join(x)+"\n"); f.flush(); os.fsync(f.fileno()); f.close(); os.replace(n,p)' "$1" "$2"; }

echo '=== CAPTA | JANELA DE ABERTURA DE SALDO INICIAL ==='
flag CIS_CASH_WRITES_APPROVED true
flag CIS_CASH_OPENING_SETUP_APPROVED true
flag CIS_CASH_OPENING_VALIDATE_APPROVED true
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || exit 1
echo 'Contas que aguardam abertura:'
sql "SELECT CONCAT(id,' | ',code,' | ',name,' | ',status) FROM cis_cash_accounts WHERE status IN ('em_preparacao','pendente_validacao') ORDER BY id;"
pending="$(sql "SELECT COUNT(*) FROM cis_cash_accounts WHERE status IN ('em_preparacao','pendente_validacao');")"
[[ "$pending" -ge 1 ]] || { echo 'Nenhuma conta em preparação. Nada a fazer.'; exit 0; }
before_active="$(sql "SELECT COUNT(*) FROM cis_cash_accounts WHERE status='activa';")"
before_ledger="$(sql 'SELECT COUNT(*),COALESCE(SUM(Valor),0),COALESCE(MAX(Nr),0) FROM Extracto;')"
echo
echo 'Durante a janela, ENTRADAS, SAÍDAS, TRANSFERÊNCIAS e ESTORNOS ficam suspensos.'
echo 'Garanta que ninguém está a lançar movimentos (incluindo o sistema Excel antigo).'
read -r -p 'Abrir janela? Digite ABRIR-JANELA-SALDO: ' answer
[[ "$answer" == 'ABRIR-JANELA-SALDO' ]] || { echo 'Cancelado.'; exit 0; }
bash deploy/backup-before-auth.sh
mkdir -p "$HOME/cis-private/cash-verification"
cp -p deploy/.env "$HOME/cis-private/cash-verification/deploy-env-before-opening-window.backup"

restored=0
restore(){
  [[ "$restored" == 1 ]] && return
  restored=1
  echo; echo 'A repor movimentações (CIS_CASH_WRITES_APPROVED=true)...'
  setflag CIS_CASH_WRITES_APPROVED true
  if "${APP[@]}" up -d --no-deps --force-recreate api-preview >/dev/null \
     && "${APP[@]}" exec -T api-preview sh -c 'test "$CIS_CASH_WRITES_APPROVED" = true'; then
    echo 'Movimentações REPOSTAS.'
  else
    echo 'ATENÇÃO: falha a reiniciar a API. Execute: bash deploy/refresh-private-preview.sh' >&2
  fi
}
trap restore EXIT
trap 'exit 130' INT TERM HUP

setflag CIS_CASH_WRITES_APPROVED false
"${APP[@]}" up -d --no-deps --force-recreate api-preview
"${APP[@]}" exec -T api-preview sh -c 'test "$CIS_CASH_WRITES_APPROVED" = false' \
  || { echo 'BLOQUEADO: flag não chegou ao container.' >&2; exit 1; }
echo
echo '>>> JANELA ABERTA. Na interface do CAPTA (actualize a página):'
echo '    Caixa Geral → Contas e Saldos → Ver detalhes → Preparar saldo inicial → Submeter'
echo '    Depois: confirmar o saldo (CONFIRMO-SALDO-REAL) para activar a conta.'
echo
read -r -p 'Quando terminar, prima ENTER para fechar a janela e repor as movimentações... ' _

after_ledger="$(sql 'SELECT COUNT(*),COALESCE(SUM(Valor),0),COALESCE(MAX(Nr),0) FROM Extracto;')"
[[ "$after_ledger" == "$before_ledger" ]] || echo 'AVISO: o Extracto mudou durante a janela (escrita externa ao CIS?). Verifique.' >&2
after_active="$(sql "SELECT COUNT(*) FROM cis_cash_accounts WHERE status='activa';")"
echo "Contas activas: antes $before_active, agora $after_active."
sql "SELECT CONCAT(code,' | ',name,' | ',status,' | saldo ',COALESCE(opening_balance,'-'),' | marcador Nr ',COALESCE(opening_extracto_nr,'-')) FROM cis_cash_accounts ORDER BY id;"
restore
echo 'JANELA DE ABERTURA FECHADA.'
