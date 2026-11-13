#!/usr/bin/env bash
# Concede permissões mínimas para editar apenas os sete novos campos de Clientes.
# Não cria tabelas, nem modifica registos nem ligações públicas.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "Falta deploy/.env" >&2; exit 1; }
command -v openssl >/dev/null || { echo "Falta openssl" >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
dbsql() {
 "${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$1"' _ "$1"
}
count="$(dbsql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND COLUMN_NAME IN ('ponto_referencia','local_trabalho_actividade','fonte_rendimento_principal','contacto_alternativo','pessoa_referencia_nome','pessoa_referencia_contacto','melhor_horario_contacto');")"
[[ "$count" == "7" ]] || { echo "Campos complementares incompletos: execute primeiro activate-client-secondary-columns.sh" >&2; exit 1; }
# Não criar um segundo editor se a conta de edição anterior já existir.
existing_user="$(grep '^CLIENT_EDITOR_DB_USERNAME=' deploy/.env | tail -n1 | cut -d= -f2- || true)"
existing_pass="$(grep '^CLIENT_EDITOR_DB_PASSWORD=' deploy/.env | tail -n1 | cut -d= -f2- || true)"
if [[ -n "$existing_user" || -n "$existing_pass" ]]; then
 if [[ "$existing_user" != "cis_editor" || -z "$existing_pass" ]]; then
  echo "Credenciais de edição existentes inesperadas ou incompletas. Revisão necessária." >&2; exit 1
 fi
 echo "A reutilizar a conta cis_editor já configurada."
 newuser=false
else
 existing_user="cis_editor"
 newuser=true
 existing_pass="$(openssl rand -hex 32)"
fi
user_count="$(dbsql "SELECT COUNT(*) FROM mysql.user WHERE user='cis_editor' AND host='%';")"
if [[ "$newuser" == true && "$user_count" != "0" ]]; then
 echo "A conta cis_editor já existe no MySQL mas não consta de deploy/.env. Não será substituída." >&2
 exit 1
fi
if [[ "$newuser" == false && "$user_count" != "1" ]]; then
 echo "A conta cis_editor esperada não foi encontrada no MySQL." >&2; exit 1
fi
# Gravação por coluna; sem INSERT, DELETE, DROP, CREATE ou alteração de tabelas legadas.
sql="GRANT SELECT (\`ID\`) ON \`cis\`.\`Clientes\` TO 'cis_editor'@'%'; GRANT UPDATE (\`ponto_referencia\`,\`local_trabalho_actividade\`,\`fonte_rendimento_principal\`,\`contacto_alternativo\`,\`pessoa_referencia_nome\`,\`pessoa_referencia_contacto\`,\`melhor_horario_contacto\`) ON \`cis\`.\`Clientes\` TO 'cis_editor'@'%';"
if [[ "$newuser" == true ]]; then
 # A password é gerada localmente, sem aparecer nos logs.
 sql="CREATE USER 'cis_editor'@'%' IDENTIFIED BY '$existing_pass'; $sql"
fi
echo "A configurar permissões limitadas de edição na tabela Clientes..."
dbsql "$sql"
umask 077
if [[ "$newuser" == true ]]; then
 printf '\nCLIENT_EDITOR_DB_USERNAME=cis_editor\nCLIENT_EDITOR_DB_PASSWORD=%s\n' "$existing_pass" >> deploy/.env
fi
if ! grep -q '^CIS_CLIENTES_EDIT_APPROVED=' deploy/.env; then
 printf 'CIS_CLIENTES_EDIT_APPROVED=true\n' >> deploy/.env
elif ! grep -qx 'CIS_CLIENTES_EDIT_APPROVED=true' deploy/.env; then
 echo "Edição directa desactivada nas opções existentes. Ajuste apenas após rever o estado." >&2
 exit 1
fi
if ! grep -q '^CIS_CLIENTES_SECONDARY_APPROVED=' deploy/.env; then
 printf 'CIS_CLIENTES_SECONDARY_APPROVED=true\n' >> deploy/.env
elif ! grep -qx 'CIS_CLIENTES_SECONDARY_APPROVED=true' deploy/.env; then
 echo "Campos complementares desactivados nas opções existentes. Ajuste após rever o estado." >&2
 exit 1
fi
chmod 600 deploy/.env
echo "Conta cis_editor preparada; campos complementares autorizados só na pré-visualização SSH privada."
echo "Actualize api-preview e web-preview antes de testar a ficha."
