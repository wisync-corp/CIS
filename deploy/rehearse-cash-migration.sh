#!/usr/bin/env bash
# Ensaiar RESTAURO e migracao da Caixa numa infraestrutura Docker ISOLADA.
# NUNCA usa o container mysql produtivo, nem a rede ou o volume do projecto cis.
# Nao publica portas. Apaga os recursos de ensaio ao terminar.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077

backups="$HOME/cis-private/backups"
backup="${1:-}"
if [[ -z "$backup" ]]; then
  [[ -d "$backups" ]] || { echo "BLOQUEADO: pasta de backups nao existe." >&2; exit 1; }
  backup="$(find "$backups" -maxdepth 1 -type f -name 'cis-before-auth-*.sql' -printf '%T@ %p\n' | sort -rn | head -n 1 | cut -d' ' -f2-)"
fi
[[ -n "$backup" && -f "$backup" && -s "$backup" ]] || { echo "BLOQUEADO: indique um backup SQL privado valido." >&2; exit 1; }
command -v docker >/dev/null || { echo "BLOQUEADO: Docker indisponivel." >&2; exit 1; }
command -v openssl >/dev/null || { echo "BLOQUEADO: openssl indisponivel." >&2; exit 1; }
[[ -f backend/Dockerfile ]] || { echo "BLOQUEADO: faltam ficheiros do backend." >&2; exit 1; }

# Os recursos de ensaio usam nomes aleatorios, nunca 'cis' nem o MySQL operacional.
nonce="$(openssl rand -hex 5)"
db="cash_rehearsal_mysql_$nonce"
network="cash_rehearsal_net_$nonce"
volume="cash_rehearsal_data_$nonce"
image_name="cis-cash-rehearsal:$nonce"
password="$(openssl rand -hex 32)"
app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
created_db=false
created_network=false
created_volume=false
created_image=false
cleanup() {
  local status=$?
  trap - EXIT
  echo "A eliminar apenas os recursos temporarios do ensaio..."
  if [[ "$created_db" == true ]]; then sudo docker rm -f "$db" >/dev/null 2>&1 || true; fi
  if [[ "$created_network" == true ]]; then sudo docker network rm "$network" >/dev/null 2>&1 || true; fi
  if [[ "$created_volume" == true ]]; then sudo docker volume rm "$volume" >/dev/null 2>&1 || true; fi
  if [[ "$created_image" == true ]]; then sudo docker image rm "$image_name" >/dev/null 2>&1 || true; fi
  if [[ "$status" -eq 0 ]]; then echo "ENSAIO ISOLADO OK — recursos eliminados; a base de producao nao foi alterada."; else
    echo "ENSAIO BLOQUEADO/FALHOU (codigo $status); nenhum comando de migracao foi executado no servidor MySQL de producao." >&2
  fi
  exit "$status"
}
trap cleanup EXIT

echo "=== ENSAIO DA MIGRACAO DA CAIXA EM RESTAURO ISOLADO ==="
echo "Ficheiro de origem: $(basename "$backup")"
echo "SHA-256 do backup: $(sha256sum "$backup" | cut -d' ' -f1)"
echo "Nenhuma palavra-passe ou dado de cliente sera exibido."
echo "A criar rede Docker interna e volume descartavel separados..."
sudo docker network create --internal "$network" >/dev/null
created_network=true
sudo docker volume create "$volume" >/dev/null
created_volume=true
sudo docker run -d --name "$db" --network "$network" --network-alias mysql-rehearsal \
  --mount "type=volume,src=$volume,dst=/var/lib/mysql" \
  --env "MYSQL_ROOT_PASSWORD=$password" --env MYSQL_DATABASE=cis \
  mysql:8.0 >/dev/null
created_db=true

ready=false
for i in $(seq 1 90); do
  if sudo docker exec -e "MYSQL_PWD=$password" "$db" mysql -uroot -N -e 'SELECT 1;' >/dev/null 2>&1; then ready=true; break; fi
  sleep 2
done
[[ "$ready" == true ]] || { echo "BLOQUEADO: o MySQL temporario nao iniciou." >&2; exit 1; }

echo "A restaurar backup na base ISOLADA..."
sudo docker exec -i -e "MYSQL_PWD=$password" "$db" mysql -uroot --default-character-set=utf8mb4 cis < "$backup"

query() {
  sudo docker exec -e "MYSQL_PWD=$password" "$db" \
    mysql -uroot --default-character-set=utf8mb4 -N -B cis -e "$1"
}
echo "A verificar a base restaurada..."
[[ "$(query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto'")" == 1 ]] || { echo "BLOQUEADO: falta Extracto restaurado." >&2; exit 1; }
[[ "$(query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='cis_cash_accounts'")" == 0 ]] || { echo "BLOQUEADO: backup ja possui cis_cash_accounts; requer analise." >&2; exit 1; }
[[ "$(query "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id'")" == 0 ]] || { echo "BLOQUEADO: backup ja possui Extracto.account_id; requer analise." >&2; exit 1; }

before="$(query 'SELECT COUNT(*), COALESCE(SUM(`Valor`),0) FROM `Extracto`')"
contract_before="$(query 'SELECT COUNT(*) FROM `Contractos`')"
echo "Extracto antes: $before"
echo "Contractos antes: $contract_before"

echo "A compilar imagem Laravel de TESTE separada..."
sudo docker build --quiet -t "$image_name" backend >/dev/null
created_image=true

echo "A executar exactamente a migracao Laravel proposta, SOMENTE no MySQL temporario..."
sudo docker run --rm --network "$network" --entrypoint php \
  -e APP_ENV=production -e APP_DEBUG=false -e "APP_KEY=$app_key" \
  -e CIS_RUN_MIGRATIONS=false \
  -e DB_CONNECTION=mysql -e DB_HOST=mysql-rehearsal -e DB_PORT=3306 \
  -e DB_DATABASE=cis -e DB_USERNAME=root -e "DB_PASSWORD=$password" \
  "$image_name" artisan migrate \
  --path=database/migrations/2026_11_16_000030_create_cis_cash_tables.php --force

echo "A comparar Extracto e Contractos ANTES e DEPOIS..."
after="$(query 'SELECT COUNT(*), COALESCE(SUM(`Valor`),0) FROM `Extracto`')"
contract_after="$(query 'SELECT COUNT(*) FROM `Contractos`')"
accounts="$(query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='cis_cash_accounts'")"
column="$(query "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cis' AND TABLE_NAME='Extracto' AND COLUMN_NAME='account_id' AND IS_NULLABLE='YES'")"
non_null="$(query 'SELECT COUNT(*) FROM `Extracto` WHERE account_id IS NOT NULL')"
[[ "$before" == "$after" ]] || { echo "BLOQUEADO: Extracto alterado durante o ensaio." >&2; exit 1; }
[[ "$contract_before" == "$contract_after" ]] || { echo "BLOQUEADO: quantidade de contratos alterada." >&2; exit 1; }
[[ "$accounts" == 1 && "$column" == 1 && "$non_null" == 0 ]] || { echo "BLOQUEADO: a nova estrutura nao corresponde ao aprovado." >&2; exit 1; }

echo "Extracto depois: $after"
echo "Contractos depois: $contract_after"
echo "Tabela cis_cash_accounts criada: $accounts"
echo "Extracto.account_id nullable criada: $column"
echo "Movimentos historicos com conta atribuida: $non_null"
echo "ENSAIO DE RESTAURO E MIGRACAO: APROVADO (somente ambiente isolado)."
echo "Producao nao foi migrada. Revisar qualquer escritor externo (Excel/VBA) e a janela de manutencao antes de alteracoes reais."
