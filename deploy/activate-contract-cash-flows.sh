#!/usr/bin/env bash
# CAPTA: ACTIVAR pagamentos de prestações e desembolsos LIGADOS À CAIXA.
#  - cobrança integral (contratos CIS e históricos) entra numa conta activa escolhida;
#  - desembolso sai de uma conta activa escolhida, com verificação do saldo dessa conta;
#  - cada alteração de prestação grava antes/depois em cis_contractos_historico.
# Pré-requisitos: Caixa com escrita activa, histórico instalado com triggers.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-contract-cash-activation.lock
flock -n 9 || { echo 'BLOQUEADO: activação concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
setflag(){ python3 -c 'import pathlib,os,sys,tempfile; k,v=sys.argv[1],sys.argv[2]; p=pathlib.Path("deploy/.env"); x=[l for l in p.read_text().splitlines() if not l.startswith(k+"=")]; x.append(k+"="+v); fd,n=tempfile.mkstemp(prefix=".contract-cash-",dir=str(p.parent)); os.fchmod(fd,0o600); f=os.fdopen(fd,"w"); f.write("\n".join(x)+"\n"); f.flush(); os.fsync(f.fileno()); f.close(); os.replace(n,p)' "$1" "$2"; }
# Verifica a flag NA API EM EXECUÇÃO (algumas vêm do deploy/.env, outras fixas no compose).
need(){ local v; v="$("${APP[@]}" exec -T api-preview printenv "$1" 2>/dev/null | tr -d '\r' || true)"
  [[ "$v" == true ]] || { echo "BLOQUEADO: $1 tem de estar activo na API ($2); valor actual: ${v:-ausente}." >&2; exit 1; }; }
"${APP[@]}" exec -T api-preview true 2>/dev/null || { echo 'BLOQUEADO: a API (api-preview) não está em execução. Execute bash deploy/refresh-private-preview.sh' >&2; exit 1; }
echo '=== CAPTA | ACTIVAR PAGAMENTOS E DESEMBOLSOS LIGADOS À CAIXA ==='
need CIS_PRIVATE_PREVIEW 'ambiente privado'
need CIS_CONTRACTS_READ_APPROVED 'consulta de contratos'
need CIS_CLIENTES_PREVIEW_APPROVED 'consulta de clientes'
need CIS_CASH_WRITES_APPROVED 'escrita da Caixa'
writer="$("${APP[@]}" exec -T api-preview printenv CONTRACT_WRITER_DB_USERNAME 2>/dev/null | tr -d '\r' || true)"
[[ "$writer" == cis_contract_writer ]] || {
  echo "BLOQUEADO: a API usa o escritor '${writer:-ausente}' (esperado cis_contract_writer)." >&2; exit 1; }
[[ -n "$("${APP[@]}" exec -T api-preview printenv CONTRACT_WRITER_DB_PASSWORD 2>/dev/null | tr -d '\r' || true)" ]] || {
  echo 'BLOQUEADO: palavra-passe do escritor de contratos ausente (deploy/activate-financial-workflows.sh).' >&2; exit 1; }
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || exit 1
[[ "$(sql "SELECT COUNT(*) FROM mysql.user WHERE User='cis_contract_writer';")" == 1 ]] || {
  echo 'BLOQUEADO: utilizador cis_contract_writer inexistente.' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_contractos_historico';")" == 1 ]] || {
  echo 'BLOQUEADO: instale primeiro o histórico: bash deploy/install-contract-history.sh' >&2; exit 1; }
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='cis_contractos_historico' AND EVENT_MANIPULATION IN ('UPDATE','DELETE') AND ACTION_STATEMENT LIKE '%SIGNAL SQLSTATE%';")" == 2 ]] || {
  echo 'BLOQUEADO: triggers de imutabilidade do histórico ausentes.' >&2; exit 1; }
for t in Contractos Extracto cis_cash_accounts cis_contractos_historico; do
  [[ "$(sql "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t';")" == InnoDB ]] || {
    echo "BLOQUEADO: $t não é InnoDB." >&2; exit 1; }
done
active="$(sql "SELECT GROUP_CONCAT(CONCAT(code,' (',name,')') SEPARATOR ', ') FROM cis_cash_accounts WHERE status='activa' AND validated_at IS NOT NULL;")"
[[ -n "$active" && "$active" != NULL ]] || { echo 'BLOQUEADO: não há contas activas validadas.' >&2; exit 1; }
"${APP[@]}" build api-preview
for f in app/Http/Controllers/Api/ContractCollectionController.php app/Http/Controllers/Api/ContractOpeningController.php app/Services/CashAccountLedger.php app/Services/ContractHistory.php; do
  [[ "$(sha256sum "backend/$f" | cut -d' ' -f1)" == "$("${APP[@]}" run --rm --no-deps --entrypoint sha256sum api-preview "/srv/cis/$f" | cut -d' ' -f1)" ]] || {
    echo "BLOQUEADO: imagem da API desactualizada ($f)." >&2; exit 1; }
done
financial="$(sql 'SELECT COUNT(*),COALESCE(SUM(Valor),0),COALESCE(MAX(Nr),0) FROM Extracto;')"
echo
echo "Contas que poderão receber pagamentos e financiar desembolsos: $active"
echo 'Permissões que serão dadas ao escritor de contratos (mínimas):'
echo '  - cis_cash_accounts: SELECT e UPDATE(updated_at) — apenas para bloquear a conta durante a operação;'
echo '  - cis_contractos_historico: SELECT e INSERT — nunca UPDATE nem DELETE.'
echo 'Regras: liquidação TOTAL apenas; data de hoje; conta e referência obrigatórias; cada pedido gravado uma só vez.'
read -r -p 'Confirma activar? Digite ACTIVAR-PAGAMENTOS-DESEMBOLSOS: ' answer
[[ "$answer" == ACTIVAR-PAGAMENTOS-DESEMBOLSOS ]] || { echo 'Cancelado. Nada foi alterado.'; exit 0; }
bash deploy/backup-before-auth.sh
mkdir -p "$HOME/cis-private/cash-verification"
cp -p deploy/.env "$HOME/cis-private/cash-verification/deploy-env-before-contract-cash.backup"
sql "GRANT SELECT ON \`cis\`.\`cis_cash_accounts\` TO 'cis_contract_writer'@'%';
GRANT UPDATE (\`updated_at\`) ON \`cis\`.\`cis_cash_accounts\` TO 'cis_contract_writer'@'%';
GRANT SELECT, INSERT ON \`cis\`.\`cis_contractos_historico\` TO 'cis_contract_writer'@'%';"
grants="$(sql "SHOW GRANTS FOR 'cis_contract_writer'@'%';")"
[[ "$grants" != *'ALL PRIVILEGES'* ]] || { echo 'BLOQUEADO: ALL PRIVILEGES no escritor.' >&2; exit 1; }
grantee="CONCAT(CHAR(39),'cis_contract_writer',CHAR(39),'@',CHAR(39),'%',CHAR(39))"
[[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=$grantee AND TABLE_NAME IN ('cis_contractos_historico','Extracto','cis_cash_accounts') AND PRIVILEGE_TYPE IN ('UPDATE','DELETE','DROP','ALTER');")" == 0 ]] || {
  echo 'BLOQUEADO: escritor tem UPDATE/DELETE em histórico, Extracto ou contas.' >&2; exit 1; }
setflag CIS_NEW_CONTRACTS_APPROVED true
setflag CIS_COLLECTION_WRITES_APPROVED true
setflag CIS_CASH_CONTRACT_FLOWS_APPROVED true
if ! "${APP[@]}" up -d --no-deps --force-recreate api-preview \
   || ! "${APP[@]}" exec -T api-preview sh -c 'test "$CIS_CASH_CONTRACT_FLOWS_APPROVED" = true'; then
  echo 'Falha a aplicar a configuração. A desactivar.' >&2
  bash deploy/disable-contract-cash-flows.sh || true
  exit 1
fi
[[ "$(sql 'SELECT COUNT(*),COALESCE(SUM(Valor),0),COALESCE(MAX(Nr),0) FROM Extracto;')" == "$financial" ]] || \
  echo 'AVISO: o Extracto mudou durante a activação (movimento legítimo em paralelo?). Verifique.' >&2
bash deploy/verify-contract-cash-flows.sh
echo 'PAGAMENTOS E DESEMBOLSOS ACTIVADOS. Nenhum pagamento foi gravado; faça o primeiro pela interface.'
echo 'Para desligar a qualquer momento: bash deploy/disable-contract-cash-flows.sh'
