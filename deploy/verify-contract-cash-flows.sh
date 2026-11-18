#!/usr/bin/env bash
# Verificação SOMENTE LEITURA da ligação contratos ↔ Caixa e do histórico imutável.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || exit 1
sudo -v
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
echo '=== VERIFICAÇÃO: PAGAMENTOS/DESEMBOLSOS ↔ CAIXA (só leitura) ==='
ok=1
chk(){ if [[ "$2" == "$3" ]]; then echo "  OK  $1"; else echo "  FALHA $1 (obtido: $2)"; ok=0; fi; }
chk 'Histórico instalado' "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_contractos_historico';")" 1
chk 'Triggers de imutabilidade (UPDATE/DELETE)' "$(sql "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='cis_contractos_historico' AND ACTION_STATEMENT LIKE '%SIGNAL SQLSTATE%';")" 2
grantee="CONCAT(CHAR(39),'cis_contract_writer',CHAR(39),'@',CHAR(39),'%',CHAR(39))"
chk 'Escritor: SELECT/INSERT no histórico' "$(sql "SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=$grantee AND TABLE_NAME='cis_contractos_historico' AND PRIVILEGE_TYPE IN ('SELECT','INSERT');")" 2
chk 'Escritor: sem UPDATE/DELETE em histórico/Extracto/contas' "$(sql "SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=$grantee AND TABLE_NAME IN ('cis_contractos_historico','Extracto','cis_cash_accounts') AND PRIVILEGE_TYPE IN ('UPDATE','DELETE');")" 0
chk 'Escritor: lock de contas (UPDATE só em updated_at)' "$(sql "SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE=$grantee AND TABLE_NAME='cis_cash_accounts' AND PRIVILEGE_TYPE='UPDATE' AND COLUMN_NAME='updated_at';")" 1
chk 'Escritor: UPDATE só nas 9 colunas de pagamento em Contractos' "$(sql "SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE=$grantee AND TABLE_NAME='Contractos' AND PRIVILEGE_TYPE='UPDATE' AND COLUMN_NAME IN ('Valor de Juros','Valor de Multa','Valor a Pagar','Valor Pago','Data de Pagamento','Estado','Dias','Situação de Pagamento','Estado do Contrato');")" 9
chk 'Escritor: sem UPDATE em Capital/Vencimento/Cliente' "$(sql "SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE=$grantee AND TABLE_NAME='Contractos' AND PRIVILEGE_TYPE='UPDATE' AND COLUMN_NAME IN ('Capital','Amortização','Data de Cobrança','Nome do Cliente','Quantia Creditada','Nr do Contracto');")" 0
flag="$("${APP[@]}" exec -T api-preview sh -c 'echo "${CIS_CASH_CONTRACT_FLOWS_APPROVED:-false}"' 2>/dev/null | tr -d '\r' || echo '?')"
echo "  Estado na API: CIS_CASH_CONTRACT_FLOWS_APPROVED=$flag"
echo "  Registos no histórico: $(sql 'SELECT COUNT(*) FROM cis_contractos_historico;' 2>/dev/null || echo 0)"
echo "  Pagamentos ligados à Caixa: $(sql "SELECT COUNT(*) FROM Extracto WHERE operation_key LIKE 'contract:collect:%';") · Desembolsos: $(sql "SELECT COUNT(*) FROM Extracto WHERE operation_key LIKE 'contract:open:%';")"
[[ "$ok" == 1 ]] && echo 'VERIFICAÇÃO APROVADA.' || { echo 'VERIFICAÇÃO COM FALHAS.' >&2; exit 1; }
