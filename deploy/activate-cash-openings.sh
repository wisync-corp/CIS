#!/usr/bin/env bash
# FASE 2 CAIXA: abertura com administrador unico expressamente autorizado.
# NUNCA activa entradas, saidas, transferencias, cobrancas ou desembolsos.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: falta deploy/.env privado.' >&2; exit 1; }
exec 9>/tmp/cis-cash-opening-setup.lock
flock -n 9 || { echo 'BLOQUEADO: activacao ja em curso.' >&2; exit 1; }
command -v openssl >/dev/null || { echo 'BLOQUEADO: openssl nao instalado.' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
DBCOMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql() {
 printf '%s\n' "$1" | "${DBCOMPOSE[@]}" exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'
}
assert_eq(){
 local got
 got="$(sql "$1")"
 [[ "$got" == "$2" ]] || { echo "BLOQUEADO: $3 (actual: $got)" >&2; exit 1; }
}
echo '=== CAIXA: PREPARACAO SEGURA DE SALDOS INICIAIS ==='
assert_eq 'SELECT DATABASE();' cis 'Base de dados nao e cis.'
assert_eq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_accounts';" 1 'Contas nao instaladas.'
assert_eq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id';" 1 'Ligacao directa Extracto.account_id ausente.'
assert_eq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_movements';" 0 'Livro duplicado detectado.'
assert_eq "SELECT COUNT(*) FROM mysql.user WHERE User IN ('cis_cash_open_submit','cis_cash_open_validate');" 0 'Utilizadores de abertura ja existem; nao recriar.'
admins="$(sql "SELECT COUNT(*) FROM users WHERE role='administrador' AND active=1;")"
if [[ "$admins" -lt 1 ]]; then
 echo 'BLOQUEADO: não existe administrador activo.' >&2
 exit 1
fi
echo "Administradores activos encontrados: $admins (não são mostradas identidades)."
# A flag de novos contratos pode estar TRUE sem ser uma via de
# desembolso habilitada: o controlador legado bloqueia logo no inicio com 503.
# Verificar o bloqueio de forma conservadora; se mudar o codigo, parar.
if grep -Eq '^CIS_CASH_WRITES_APPROVED=true$|^CIS_COLLECTION_WRITES_APPROVED=true
if grep -Eq '^CIS_CASH_OPENING_(SETUP|VALIDATE)_APPROVED=true$|^CASH_OPENING_(SUBMIT|VALIDATE)_DB_PASSWORD=' deploy/.env; then
 echo 'BLOQUEADO: configuracao previa encontrada; rever manualmente, nao repetir.' >&2; exit 1
fi
echo 'Privilégios previstos: SELECT e UPDATE SOMENTE campos de abertura de cis_cash_accounts.'
echo 'Modo solicitado: o mesmo administrador prepara e confirma a abertura.'
echo 'ATENCAO: sem segregacao de funções humanas; confirmacao de documento obrigatoria.'
echo 'Sem permissoes de escrita no Extracto e sem insercoes financeiras.'
read -r -p 'Autoriza abertura com administrador unico? Digite ADMIN-UNICO-CAIXA: ' approval
[[ "$approval" == ADMIN-UNICO-CAIXA ]] || { echo 'Cancelado sem alteracoes.'; exit 0; }
echo 'A criar backup antes da alteracao de permissoes...'
bash deploy/backup-before-auth.sh
submit_pw="$(openssl rand -hex 32)"
validate_pw="$(openssl rand -hex 32)"
# Utilizadores distintos, grants apenas na tabela de contas.
# Não conceder UPDATE de identificador, moeda, codigo, valor inicial ao aprovador.
sql "CREATE USER 'cis_cash_open_submit'@'%' IDENTIFIED BY '$submit_pw';
GRANT SELECT ON \`cis\`.\`cis_cash_accounts\` TO 'cis_cash_open_submit'@'%';
GRANT UPDATE (opening_balance,cut_at,evidence_reference,status,updated_at) ON \`cis\`.\`cis_cash_accounts\` TO 'cis_cash_open_submit'@'%';
CREATE USER 'cis_cash_open_validate'@'%' IDENTIFIED BY '$validate_pw';
GRANT SELECT ON \`cis\`.\`cis_cash_accounts\` TO 'cis_cash_open_validate'@'%';
GRANT UPDATE (opening_extracto_nr,validated_by,validated_at,status,updated_at) ON \`cis\`.\`cis_cash_accounts\` TO 'cis_cash_open_validate'@'%';"
{
 printf '\nCASH_OPENING_SUBMIT_DB_USERNAME=cis_cash_open_submit\n'
 printf 'CASH_OPENING_SUBMIT_DB_PASSWORD=%s\n' "$submit_pw"
 printf 'CASH_OPENING_VALIDATE_DB_USERNAME=cis_cash_open_validate\n'
 printf 'CASH_OPENING_VALIDATE_DB_PASSWORD=%s\n' "$validate_pw"
 printf 'CIS_CASH_OPENING_SETUP_APPROVED=true\n'
 printf 'CIS_CASH_OPENING_VALIDATE_APPROVED=true\n'
 printf 'CIS_CASH_SINGLE_ADMIN_APPROVED=true\n'
} >> deploy/.env
chmod 600 deploy/.env
echo 'A reconstruir API privada com as credenciais de abertura...'
"${COMPOSE[@]}" up -d --no-deps --build --force-recreate api-preview
echo 'A verificar ligações e proibir escrita no Extracto...'
"${COMPOSE[@]}" exec -T api-preview php -r '
require "/srv/cis/vendor/autoload.php";
$app=require "/srv/cis/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach(["mysql_cash_opening_submit","mysql_cash_opening_validate"] as $name){
 $db=\Illuminate\Support\Facades\DB::connection($name);
 if($db->getDatabaseName()!=="cis")exit(2);
 $db->select("SELECT id FROM cis_cash_accounts LIMIT 0");
}
if(filter_var(env("CIS_CASH_WRITES_APPROVED",false),FILTER_VALIDATE_BOOLEAN))exit(3);
echo "OK: dois utilizadores separados, SELECT sem escrita; movimentos permanecem bloqueados.\n";
'
echo 'ABERTURA PREPARADA PARA ADMINISTRADOR UNICO, COM CONFIRMACAO DOCUMENTAL.'
echo 'ATENCAO: em caso de falha, nao repetir este script; inspeccionar estado das credenciais e grants.'
 deploy/.env; then
 echo 'BLOQUEADO: escrita real de Caixa ou Cobranças parece habilitada.' >&2
 exit 1
fi
if grep -qx 'CIS_NEW_CONTRACTS_APPROVED=true' deploy/.env; then
 python3 - <<'PY'
from pathlib import Path
import sys
source=Path('backend/app/Http/Controllers/Api/ContractOpeningController.php').read_text()
start=source.find('public function store(Request $request)')
if start<0:
    sys.exit('BLOQUEADO: controlador de desembolsos nao localizado.')
body=source[start:]
block=body.find("abort(503, 'Abertura com desembolso temporariamente suspensa:")
first_user=body.find('$user = $request->user()')
if block<0 or first_user<0 or block>first_user:
    sys.exit('BLOQUEADO: a flag de contratos esta activa e o bloqueio de desembolso nao foi comprovado.')
print('CIS_NEW_CONTRACTS_APPROVED=true: aceite apenas porque o desembolso continua bloqueado por HTTP 503 antes da operacao.')
PY
fi
if grep -Eq '^CIS_CASH_OPENING_(SETUP|VALIDATE)_APPROVED=true$|^CASH_OPENING_(SUBMIT|VALIDATE)_DB_PASSWORD=' deploy/.env; then
 echo 'BLOQUEADO: configuracao previa encontrada; rever manualmente, nao repetir.' >&2; exit 1
fi
echo 'Privilégios previstos: SELECT e UPDATE SOMENTE campos de abertura de cis_cash_accounts.'
echo 'Modo solicitado: o mesmo administrador prepara e confirma a abertura.'
echo 'ATENCAO: sem segregacao de funções humanas; confirmacao de documento obrigatoria.'
echo 'Sem permissoes de escrita no Extracto e sem insercoes financeiras.'
read -r -p 'Autoriza abertura com administrador unico? Digite ADMIN-UNICO-CAIXA: ' approval
[[ "$approval" == ADMIN-UNICO-CAIXA ]] || { echo 'Cancelado sem alteracoes.'; exit 0; }
echo 'A criar backup antes da alteracao de permissoes...'
bash deploy/backup-before-auth.sh
submit_pw="$(openssl rand -hex 32)"
validate_pw="$(openssl rand -hex 32)"
# Utilizadores distintos, grants apenas na tabela de contas.
# Não conceder UPDATE de identificador, moeda, codigo, valor inicial ao aprovador.
sql "CREATE USER 'cis_cash_open_submit'@'%' IDENTIFIED BY '$submit_pw';
GRANT SELECT ON \`cis\`.\`cis_cash_accounts\` TO 'cis_cash_open_submit'@'%';
GRANT UPDATE (opening_balance,cut_at,evidence_reference,status,updated_at) ON \`cis\`.\`cis_cash_accounts\` TO 'cis_cash_open_submit'@'%';
CREATE USER 'cis_cash_open_validate'@'%' IDENTIFIED BY '$validate_pw';
GRANT SELECT ON \`cis\`.\`cis_cash_accounts\` TO 'cis_cash_open_validate'@'%';
GRANT UPDATE (opening_extracto_nr,validated_by,validated_at,status,updated_at) ON \`cis\`.\`cis_cash_accounts\` TO 'cis_cash_open_validate'@'%';"
{
 printf '\nCASH_OPENING_SUBMIT_DB_USERNAME=cis_cash_open_submit\n'
 printf 'CASH_OPENING_SUBMIT_DB_PASSWORD=%s\n' "$submit_pw"
 printf 'CASH_OPENING_VALIDATE_DB_USERNAME=cis_cash_open_validate\n'
 printf 'CASH_OPENING_VALIDATE_DB_PASSWORD=%s\n' "$validate_pw"
 printf 'CIS_CASH_OPENING_SETUP_APPROVED=true\n'
 printf 'CIS_CASH_OPENING_VALIDATE_APPROVED=true\n'
 printf 'CIS_CASH_SINGLE_ADMIN_APPROVED=true\n'
} >> deploy/.env
chmod 600 deploy/.env
echo 'A reconstruir API privada com as credenciais de abertura...'
"${COMPOSE[@]}" up -d --no-deps --build --force-recreate api-preview
echo 'A verificar ligações e proibir escrita no Extracto...'
"${COMPOSE[@]}" exec -T api-preview php -r '
require "/srv/cis/vendor/autoload.php";
$app=require "/srv/cis/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach(["mysql_cash_opening_submit","mysql_cash_opening_validate"] as $name){
 $db=\Illuminate\Support\Facades\DB::connection($name);
 if($db->getDatabaseName()!=="cis")exit(2);
 $db->select("SELECT id FROM cis_cash_accounts LIMIT 0");
}
if(filter_var(env("CIS_CASH_WRITES_APPROVED",false),FILTER_VALIDATE_BOOLEAN))exit(3);
echo "OK: dois utilizadores separados, SELECT sem escrita; movimentos permanecem bloqueados.\n";
'
echo 'ABERTURA PREPARADA PARA ADMINISTRADOR UNICO, COM CONFIRMACAO DOCUMENTAL.'
echo 'ATENCAO: em caso de falha, nao repetir este script; inspeccionar estado das credenciais e grants.'
