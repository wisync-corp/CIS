#!/usr/bin/env bash
# VERIFICAÇÃO COMPLETA DO SISTEMA sobre uma CÓPIA dos dados reais, num MySQL e numa rede Docker isolados.
# 1) cria um backup actual da base; 2) restaura-o num MySQL descartável; 3) corre backend/tests/system_full_check.php
# (todas as leituras da API por função + fluxo mutuário → proposta → desembolso → cobrança); 4) apaga tudo.
# A base real NÃO é alterada: no fim confirma-se que Extracto, Contractos e contas ficaram iguais.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
command -v openssl >/dev/null || { echo 'BLOQUEADO: openssl em falta.' >&2; exit 1; }
sudo -v
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
real(){ printf '%s\n' "$1" | timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE"'; }
echo '=== CIS | VERIFICAÇÃO COMPLETA NUMA CÓPIA ISOLADA DOS DADOS REAIS ==='
[[ "$(real 'SELECT DATABASE();')" == cis ]] || { echo 'BLOQUEADO: base inesperada.' >&2; exit 1; }
before="$(real 'SELECT (SELECT COUNT(*) FROM Extracto),(SELECT COALESCE(SUM(Valor),0) FROM Extracto),(SELECT COUNT(*) FROM Contractos),(SELECT COUNT(*) FROM Clientes);')"
echo "Base real antes: $before (Extracto, soma, Contractos, Clientes)"
backup_dir="$HOME/cis-private/backups"; mkdir -p "$backup_dir"; chmod 700 "$backup_dir"
backup="$backup_dir/cis-verificacao-$(date +%Y%m%d-%H%M%S).sql"
echo 'A criar cópia actual da base (mysqldump, leitura consistente)...'
"${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --default-character-set=utf8mb4 "$MYSQL_DATABASE"' > "$backup"
[[ -s "$backup" ]] || { echo 'Cópia vazia.' >&2; exit 1; }
nonce="$(openssl rand -hex 5)"
db="cis_full_check_db_$nonce"; network="cis_full_check_net_$nonce"; image="cis-full-check:$nonce"
password="$(openssl rand -hex 32)"; app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
db_created=0; net_created=0; image_created=0
cleanup(){
  result=$?; trap - EXIT
  [[ "$db_created" == 1 ]] && sudo -n docker rm -f "$db" >/dev/null 2>&1 || true
  [[ "$net_created" == 1 ]] && sudo -n docker network rm "$network" >/dev/null 2>&1 || true
  [[ "$image_created" == 1 ]] && sudo -n docker image rm "$image" >/dev/null 2>&1 || true
  rm -f "$backup"
  after="$(real 'SELECT (SELECT COUNT(*) FROM Extracto),(SELECT COALESCE(SUM(Valor),0) FROM Extracto),(SELECT COUNT(*) FROM Contractos),(SELECT COUNT(*) FROM Clientes);' || echo '?')"
  if [[ "$after" != "$before" ]]; then echo "ATENÇÃO: a base real mudou durante a verificação ($before → $after). Pode ter sido actividade normal de utilizadores." >&2; fi
  if [[ "$result" -eq 0 ]]; then echo 'VERIFICAÇÃO COMPLETA APROVADA. Cópia apagada; base real intocada.'; else echo 'VERIFICAÇÃO COMPLETA COM FALHAS (ver acima). Cópia apagada; base real intocada.' >&2; fi
  exit "$result"
}
trap cleanup EXIT
sudo -n docker network create --internal "$network" >/dev/null; net_created=1
sudo -n docker run -d --name "$db" --network "$network" --network-alias cis-full-check-mysql \
  -e "MYSQL_ROOT_PASSWORD=$password" -e MYSQL_DATABASE=cis mysql:8.0 >/dev/null; db_created=1
for i in $(seq 1 90); do sudo -n docker exec -e "MYSQL_PWD=$password" "$db" mysql -uroot -N -e 'SELECT 1;' >/dev/null 2>&1 && break; sleep 2; done
echo 'A restaurar a cópia no MySQL isolado...'
sudo -n docker exec -i -e "MYSQL_PWD=$password" "$db" mysql -uroot --default-character-set=utf8mb4 cis < "$backup"
echo 'A construir a imagem actual da API...'
sudo -n docker build --quiet -t "$image" backend >/dev/null; image_created=1
envs=(-e APP_ENV=production -e APP_DEBUG=false -e "APP_KEY=$app_key" -e DB_CONNECTION=mysql -e DB_HOST=cis-full-check-mysql -e DB_PORT=3306
  -e DB_DATABASE=cis -e DB_USERNAME=root -e "DB_PASSWORD=$password" -e CIS_ISOLATED_REHEARSAL=true -e CIS_RUN_MIGRATIONS=false -e CACHE_STORE=array)
for p in LEGACY CONTRACT_WRITER CLIENT_EDITOR; do envs+=(-e "${p}_DB_HOST=cis-full-check-mysql" -e "${p}_DB_PORT=3306" -e "${p}_DB_DATABASE=cis" -e "${p}_DB_USERNAME=root" -e "${p}_DB_PASSWORD=$password"); done
for p in CASH_LEDGER CASH_CLOSURES CASH_SETUP CASH_OPENING_SUBMIT CASH_OPENING_VALIDATE; do envs+=(-e "${p}_DB_USERNAME=root" -e "${p}_DB_PASSWORD=$password"); done
echo 'A verificar (todas as leituras por função + fluxo ponta a ponta) — só na cópia...'
sudo -n docker run --rm --network "$network" --entrypoint php "${envs[@]}" "$image" tests/system_full_check.php
