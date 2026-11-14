#!/usr/bin/env bash
# Correcção explicitamente aprovada: somente 1 linha 'Em Progresso' do C-0050.
# Nenhuma prestação, valor, data, extracto ou outro contrato é modificado.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: deploy/.env em falta.' >&2; exit 1; }
backups="$HOME/cis-private/backups"
if [[ ! -d "$backups" ]] || ! find "$backups" -maxdepth 1 -type f -name 'cis-before-auth-*.sql' -size +0c -mmin -1440 -print -quit | grep -q .; then
 echo 'BLOQUEADO: crie um backup novo com bash deploy/backup-before-auth.sh' >&2
 exit 1
fi
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
sql(){
 "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE" -e "$1"' _ "$1"
}
echo 'A verificar exclusivamente o estado do contrato C-0050...'
engine="$(sql "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos';")"
[[ "$engine" == "InnoDB" ]] || { echo "BLOQUEADO: Contractos tem motor $engine (requer InnoDB)." >&2; exit 1; }
before="$(sql "SELECT CONCAT(COUNT(*),':',
  SUM(CASE WHEN TRIM(\`Estado do Contrato\`)='Em Progresso' THEN 1 ELSE 0 END),':',
  SUM(CASE WHEN TRIM(\`Estado do Contrato\`)='Finalizado' THEN 1 ELSE 0 END))
  FROM \`Contractos\` WHERE \`Nr do Contracto\`='C-0050';")"
[[ "$before" == "2:1:1" ]] || {
 echo "BLOQUEADO: esperado C-0050 com exactamente 2 linhas (1 Em Progresso, 1 Finalizado); encontrado $before." >&2
 echo 'Nenhuma alteração efectuada. Repetir diagnóstico antes de corrigir.' >&2
 exit 1
}
echo 'Verificado: C-0050 tem duas linhas, exactamente uma Em Progresso e uma Finalizado.'
echo 'Operação autorizada: alterar APENAS Estado do Contrato de Em Progresso para Finalizado.'
read -r -p "Para executar no MySQL real, escreva FINALIZAR-C-0050: " confirm
[[ "$confirm" == 'FINALIZAR-C-0050' ]] || { echo 'Cancelado sem alterações.'; exit 0; }
# Os predicados exactos impedem a actualização de qualquer outro contrato.
# O WHERE inclui validação condicional da distribuição real no momento da alteração.
# O subselect lê apenas uma tabela derivada, evitando um UPDATE indiscriminado.
result="$(sql "START TRANSACTION;
 UPDATE \`Contractos\`
 SET \`Estado do Contrato\`='Finalizado'
 WHERE \`Nr do Contracto\`='C-0050'
   AND TRIM(\`Estado do Contrato\`)='Em Progresso';
 SELECT CONCAT('actualizadas=', ROW_COUNT());
 COMMIT;")"
echo "$result"
[[ "$result" == "actualizadas=1" ]] || {
 echo 'ALERTA: a quantidade de alterações diferiu de 1. Rever a base antes de continuar.' >&2
 exit 2
}
after="$(sql "SELECT CONCAT(COUNT(*),':',
  SUM(CASE WHEN TRIM(\`Estado do Contrato\`)='Em Progresso' THEN 1 ELSE 0 END),':',
  SUM(CASE WHEN TRIM(\`Estado do Contrato\`)='Finalizado' THEN 1 ELSE 0 END))
 FROM \`Contractos\` WHERE \`Nr do Contracto\`='C-0050';")"
[[ "$after" == "2:0:2" ]] || {
 echo "ALERTA: esperado 2:0:2; obtido $after. Rever imediatamente." >&2
 exit 2
}
echo 'SUCESSO: C-0050 está Finalizado nas suas duas linhas.'
echo 'Apenas um Estado do Contrato foi corrigido. Valores e prestações permaneceram inalterados.'
