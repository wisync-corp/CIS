#!/usr/bin/env bash
# ACTIVAR entradas/saídas/transferências gerais em contas já activas.
# NUNCA activar sem ensaio MySQL isolado, escritor restrito e comprovativos.
# Não activa créditos/desembolsos nem cobranças contratuais.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
for bin in flock sha256sum python3; do command -v "$bin" >/dev/null || exit 1; done
sudo -v
exec 9>/tmp/cis-cash-posting-activation.lock
flock -n 9 || { echo 'BLOQUEADO: outra activação em curso.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql() {
 printf '%s\n' "$1" | timeout 35 "${DB[@]}" exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'
}
check() {
 local value
 value="$(sql "$1")"
 [[ "$value" == "$2" ]] || { echo "BLOQUEADO: $3 (valor $value)." >&2; exit 1; }
}
echo '=== CAIXA GERAL: ACTIVACAO SUPERVISIONADA DE ESCRITURA ==='
if grep -Eq '^(CIS_CASH_WRITES_APPROVED|CIS_CASH_LEDGER_POSTING_APPROVED)=true$' deploy/.env; then
 echo 'BLOQUEADO: escrita já parcial ou totalmente configurada; não repetir.' >&2
 exit 1
fi
if grep -qx 'CIS_COLLECTION_WRITES_APPROVED=true' deploy/.env; then
 echo 'BLOQUEADO: cobrança contratual já habilitada sem homologação de integração.' >&2; exit 1
fi
[[ -s "$HOME/cis-private/cash-verification/real-posting-rehearsal.sha256" ]] || {
 echo 'BLOQUEADO: ensaio isolado de movimentos reais não foi aprovado nesta VPS.' >&2
 echo 'Executar bash deploy/rehearse-cash-real-posting.sh e verificar o resultado.' >&2
 exit 1
}
approved_sha="$(cat "$HOME/cis-private/cash-verification/real-posting-rehearsal.sha256")"
actual_sha="$(sha256sum backend/app/Http/Controllers/Api/CashPostingController.php | awk '{print $1}')"
[[ "$approved_sha" == "$actual_sha" ]] || {
 echo 'BLOQUEADO: código de lançamento difere da versão ensaiada.' >&2; exit 1
}
check 'SELECT DATABASE();' cis 'Base de dados inesperada'
check "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND ENGINE='InnoDB';" 1 'Extracto não é InnoDB'
check "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts' AND ENGINE='InnoDB';" 1 'Contas não são InnoDB'
check "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id';" 1 'account_id em falta'
check "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='operation_key' AND IS_NULLABLE='YES';" 1 'operation_key em falta'
check "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND INDEX_NAME='cis_extracto_operation_key_uq' AND NON_UNIQUE=0;" 1 'UNIQUE em falta'
check "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('cis_cash_links','cis_cash_movements');" 0 'livro paralelo detectado'
check "SELECT COUNT(*) FROM cis_cash_accounts WHERE status IN ('em_preparacao','pendente_validacao');" 0 'Existem contas em preparação; completar abertura antes de activar escritas'
check "SELECT COUNT(*) FROM cis_cash_accounts WHERE status='activa' AND (opening_balance IS NULL OR opening_extracto_nr IS NULL OR validated_at IS NULL OR cut_at IS NULL);" 0 'Conta activa com abertura incompleta'
check "SELECT COUNT(*) FROM Extracto e LEFT JOIN cis_cash_accounts a ON e.account_id=a.id WHERE e.account_id IS NOT NULL AND a.id IS NULL;" 0 'Movimento sem conta válida'
active="$(sql "SELECT COUNT(*) FROM cis_cash_accounts WHERE status='activa';")"
[[ "$active" -ge 1 ]] || { echo 'BLOQUEADO: activar e comprovar pelo menos uma conta real.' >&2; exit 1; }
echo "Contas activas verificadas: $active."
echo 'A verificar permissões e ligação do escritor sem efectuar movimentos...'
bash deploy/verify-cash-ledger-writer.sh
echo 'A construir imagem actual para comparação de código...'
"${APP[@]}" build api-preview
inside_sha="$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview /srv/cis/app/Http/Controllers/Api/CashPostingController.php | awk '{print $1}')"
[[ "$actual_sha" == "$inside_sha" ]] || {
 echo 'BLOQUEADO: imagem da API não corresponde ao código ensaiado.' >&2; exit 1
}
echo 'A fazer backup antes da mudança de permissões da API...'
bash deploy/backup-before-auth.sh
echo 'A operação irá activar APENAS movimentos gerais: entrada, saída e transferência.'
echo 'Não irá activar concessões, cobranças nem estornos de contratos.'
echo 'ATENÇÃO: a preparação de NOVAS aberturas de conta fica temporariamente suspensa enquanto as escritas estiverem activas.'
read -r -p 'Autoriza escrita real de Caixa com uma conta já confirmada? Digite ACTIVAR-CAIXA-REAL: ' approval
[[ "$approval" == 'ACTIVAR-CAIXA-REAL' ]] || { echo 'Cancelado sem alterações.'; exit 0; }
# Escrever as flags de forma atómica, sem imprimir palavras-passe nem truncar env.
cp -p deploy/.env "$HOME/cis-private/cash-verification/deploy-env-before-cash-activation.backup"
python3 - <<'PY'
from pathlib import Path
import os,tempfile
p=Path('deploy/.env')
lines=p.read_text().splitlines()
keys={'CIS_CASH_WRITES_APPROVED':'true','CIS_CASH_LEDGER_POSTING_APPROVED':'true'}
lines=[line for line in lines if not any(line.startswith(k+'=') for k in keys)]
lines.extend(f'{k}={v}' for k,v in keys.items())
fd,tmp=tempfile.mkstemp(prefix='.cis-cash-flags-',dir=str(p.parent))
try:
    os.fchmod(fd,0o600)
    with os.fdopen(fd,'w') as f:
        f.write('\n'.join(lines)+'\n');f.flush();os.fsync(f.fileno())
    os.replace(tmp,p)
finally:
    if os.path.exists(tmp):os.unlink(tmp)
PY
echo 'A reconstruir a API com escrituração específica de Caixa activada...'
if ! "${APP[@]}" up -d --no-deps --build --force-recreate api-preview; then
 echo 'AVISO: reinício da API falhou; a restabelecer as flags DESACTIVADAS.' >&2
 python3 - <<'PY'
from pathlib import Path
p=Path('deploy/.env')
c=p.read_text()
for k in ['CIS_CASH_WRITES_APPROVED','CIS_CASH_LEDGER_POSTING_APPROVED']:
    c=c.replace(k+'=true',k+'=false')
p.write_text(c)
p.chmod(0o600)
PY
 "${APP[@]}" up -d --no-deps --force-recreate api-preview || true
 exit 1
fi
echo 'ACTIVACAO DE MOVIMENTOS GERAIS CONCLUIDA.'
echo 'Verificar no CAPTA se a opção Confirmar surge APENAS depois da prévia.'
echo 'Nunca criar operação financeira fictícia para teste na base operacional.'
