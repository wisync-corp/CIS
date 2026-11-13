#!/usr/bin/env bash
# Activa edicao directa, com utilizador MySQL limitado e auditoria transaccional.
# O SQL legado original e os dados actuais NAO sao alterados por este setup.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: falta deploy/.env"; exit 1; }
[[ "$(grep -Ec '^CIS_CLIENTES_PREVIEW_APPROVED=true$' deploy/.env || true)" == 1 ]] || { echo "ERRO: consulta privada de Clientes tem de estar autorizada primeiro."; exit 1; }
for var in CLIENT_EDITOR_DB_USERNAME CLIENT_EDITOR_DB_PASSWORD CIS_CLIENTES_EDIT_APPROVED; do
 if grep -q "^$var=" deploy/.env; then
   echo "ERRO: a variavel $var ja existe. Pare e reveja, para nao duplicar nem alterar credenciais."
   exit 1
 fi
done
backups="$HOME/cis-private/backups"
if [[ ! -d "$backups" ]] || ! find "$backups" -maxdepth 1 -type f -name 'cis-before-auth-*.sql' -size +0c -mmin -1440 -print -quit | grep -q .; then
 echo "ERRO: criar antes um backup novo com: bash deploy/backup-before-auth.sh"
 exit 1
fi
# Integridade transaccional e identificador seguro são obrigatórios para editar dados reais.
# Não alterar o motor MySQL nem criar índice automaticamente.
meta_sql="SELECT CONCAT(COALESCE((SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes'),'MISSING'),':',COALESCE((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND INDEX_NAME='PRIMARY' AND COLUMN_NAME='ID' AND SEQ_IN_INDEX=1),0),':',COALESCE((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Clientes' AND INDEX_NAME='PRIMARY'),0),':',COALESCE((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_client_edit_audit'),0));"
meta="$(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$MYSQL_DATABASE" -e "$1"' _ "$meta_sql")"
if [[ "$meta" != "InnoDB:1:1:0" ]]; then
 echo "BLOQUEADO: Clientes deve usar InnoDB, ter PRIMARY KEY em ID e não existir auditoria anterior." >&2
 echo "Metadados (motor:PK_ID:total_colunas_PK:auditoria) = $meta" >&2
 echo "Não foi realizada nenhuma alteração; precisamos rever o esquema antes de activar edição directa." >&2
 exit 1
fi
read -r -p "Confirma edicao DIRECTA de Nome, Bairro, Cidade, Provincia e Rua na base original? Escreva ACTIVAR: " confirm
[[ "$confirm" == "ACTIVAR" ]] || { echo "Cancelado."; exit 1; }
umask 077
editor_pass="$(openssl rand -hex 32)"
sql_tmp="$(mktemp "$HOME/cis-private/editor-setup.XXXXXXXX.sql")"
trap 'rm -f "$sql_tmp"' EXIT
cat > "$sql_tmp" <<SQL
CREATE TABLE \`cis_client_edit_audit\` (
 \`id\` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 \`client_id\` INT NOT NULL,
 \`actor_user_id\` BIGINT UNSIGNED NOT NULL,
 \`field_name\` VARCHAR(100) NOT NULL,
 \`old_value\` TEXT NULL,
 \`new_value\` TEXT NULL,
 \`changed_at\` DATETIME NOT NULL,
 INDEX \`cis_client_edit_idx\` (\`client_id\`,\`changed_at\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE USER 'cis_editor'@'%' IDENTIFIED BY '$editor_pass';
GRANT SELECT ON \`cis\`.\`Clientes\` TO 'cis_editor'@'%';
GRANT UPDATE (\`Nome Completo\`,\`Bairro\`,\`Cidade\`,\`Provincia\`,\`Rua\`) ON \`cis\`.\`Clientes\` TO 'cis_editor'@'%';
GRANT INSERT ON \`cis\`.\`cis_client_edit_audit\` TO 'cis_editor'@'%';
SQL
echo "A criar apenas tabela de auditoria e conta MySQL com privilégios mínimos..."
sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot "$MYSQL_DATABASE"' < "$sql_tmp"
{
 printf '\nCLIENT_EDITOR_DB_USERNAME=cis_editor\n'
 printf 'CLIENT_EDITOR_DB_PASSWORD=%s\n' "$editor_pass"
 printf 'CIS_CLIENTES_EDIT_APPROVED=true\n'
} >> deploy/.env
chmod 600 deploy/.env
echo "Edição directa preparada; conta separada com SELECT em Clientes, UPDATE limitado e INSERT em auditoria."
echo "Atenção: o acesso é APENAS via pré-visualização SSH privada e administrador."
echo "Agora reinicie a API e o React: bash deploy/start-private-preview.sh"
