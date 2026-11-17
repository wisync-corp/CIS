#!/usr/bin/env bash
# FASE 2 CAIXA: aprovar apenas abertura/validacao de saldos sob 2 identidades.
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
if [[ "$admins" -lt 2 ]]; then
 echo 'BLOQUEADO: a validação exige pelo menos dois administradores activos distintos.' >&2
 echo 'Criar e autenticar o segundo responsável antes de activar o fluxo.' >&2
 exit 1
fi
echo "Controlo de dupla validação: $admins administradores activos (identidades não mostradas)."
if grep -Eq '^CIS_CASH_WRITES_APPROVED=true$|^CIS_COLLECTION_WRITES_APPROVED=true$|^CIS_NEW_CONTRACTS_APPROVED=true$' deploy/.env; then
 echo 'BLOQUEADO: outra via financeira pode estar habilitada. Exige revisao independente.' >&2; exit 1
fi
if grep -Eq '^CIS_CASH_OPENING_(SETUP|VALIDATE)_APPROVED=true$|^CASH_OPENING_(SUBMIT|VALIDATE)_DB_PASSWORD=' deploy/.env; then
 echo 'BLOQUEADO: configuracao previa encontrada; rever manualmente, nao repetir.' >&2; exit 1
fi
echo 'Privilégios previstos: SELECT e UPDATE SOMENTE campos de abertura de cis_cash_accounts.'
echo 'Responsavel pelo cadastro prepara a proposta; segundo administrador valida.'
echo 'Sem permissoes de escrita no Extracto e sem insercoes financeiras.'
read -r -p 'Autoriza esta fase de abertura com dois responsaveis? Digite PREPARAR-CAIXA: ' approval
[[ "$approval" == PREPARAR-CAIXA ]] || { echo 'Cancelado sem alteracoes.'; exit 0; }
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
echo 'ABERTURA DE CONTAS PREPARADA. Exige segundo administrador para validar saldos.'
echo 'ATENCAO: em caso de falha, nao repetir este script; inspeccionar estado das credenciais e grants.'
