#!/usr/bin/env bash
# CAPTA: correcção pontual e auditável de linhas históricas do Extracto que o CIS
# não consegue ler (antes de atribuir o histórico ao Numerário).
#
#   bash deploy/fix-legacy-extracto-rows.sh <Nr> <valor_correcto>   # corrige Valor de UMA linha
#   bash deploy/fix-legacy-extracto-rows.sh --tipos                 # só normaliza 'Saidas' -> 'Saídas'
#   bash deploy/fix-legacy-extracto-rows.sh --reverter              # repõe os valores originais
#
# Em todos os modos, normaliza também o tipo exacto 'Saidas' (sem acento) para 'Saídas'.
# Só linhas históricas (account_id e operation_key NULL). Backup antes; estado guardado.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-legacy-numerario.lock
flock -n 9 || { echo 'BLOQUEADO: operação concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 60 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
state_dir="$HOME/cis-private/cash-verification"
state="$state_dir/legacy-extracto-fix.state"
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || exit 1
cs="$(sql "SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Extracto' AND COLUMN_NAME='Tipo de Movimento';")"
[[ "$cs" =~ ^(latin1|utf8mb3|utf8|utf8mb4)$ ]] || { echo "BLOQUEADO: charset inesperado ($cs)." >&2; exit 1; }
[[ "$cs" == utf8 ]] && cs=utf8mb3
BIN="COLLATE ${cs}_bin"

if [[ "${1:-}" == --reverter ]]; then
  [[ -s "$state" ]] || { echo 'Nada a reverter.' >&2; exit 1; }
  [[ "$(sql 'SELECT COUNT(*) FROM Extracto WHERE account_id IS NOT NULL AND operation_key IS NULL;')" == 0 ]] || {
    echo 'BLOQUEADO: o histórico já foi atribuído a uma conta. Reverta primeiro revert-legacy-extracto-numerario.sh.' >&2; exit 1; }
  echo 'Repor:'; cat "$state"
  read -r -p 'Digite REVERTER-CORRECCAO-EXTRACTO: ' a; [[ "$a" == REVERTER-CORRECCAO-EXTRACTO ]] || exit 0
  bash deploy/backup-before-auth.sh
  stmts="START TRANSACTION;"
  while IFS=$'\t' read -r kind nr old; do
    [[ "$nr" =~ ^[0-9]+$ ]] || continue
    if [[ "$kind" == tipo ]]; then stmts+=" UPDATE Extracto SET \`Tipo de Movimento\`='Saidas' WHERE Nr=$nr AND account_id IS NULL AND operation_key IS NULL;"
    elif [[ "$kind" == valor && "$old" =~ ^[0-9.eE+-]+$ ]]; then stmts+=" UPDATE Extracto SET Valor=$old WHERE Nr=$nr AND account_id IS NULL AND operation_key IS NULL;"; fi
  done < "$state"
  sql "$stmts COMMIT;" >/dev/null
  mv "$state" "$state.reverted-$(date +%Y%m%d%H%M%S)"
  echo 'CORRECÇÃO REVERTIDA.'; exit 0
fi
[[ ! -e "$state" ]] || { echo "BLOQUEADO: já existe correcção registada ($state). Use --reverter antes de repetir." >&2; exit 1; }

NR=""; NEWV=""
if [[ "${1:-}" != --tipos ]]; then
  NR="${1:-}"; NEWV="${2:-}"
  [[ "$NR" =~ ^[1-9][0-9]*$ && "$NEWV" =~ ^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$ ]] || {
    echo 'Uso: bash deploy/fix-legacy-extracto-rows.sh <Nr> <valor com até 2 casas> | --tipos | --reverter' >&2; exit 1; }
fi

echo '=== CAPTA | CORRECÇÃO DE LINHAS HISTÓRICAS DO EXTRACTO ==='
echo 'Tipos de movimento fora do padrão (comparação exacta, com acentos):'
sql "SELECT CONCAT('Nr ',Nr,' | ',\`Data da Operação\`,' | \"',\`Tipo de Movimento\`,'\" | ',Valor,' | ',COALESCE(\`Descrição\`,'')) FROM Extracto WHERE \`Tipo de Movimento\` $BIN NOT IN ('Entradas','Saídas') ORDER BY Nr;"
other="$(sql "SELECT COUNT(*) FROM Extracto WHERE \`Tipo de Movimento\` $BIN NOT IN ('Entradas','Saídas','Saidas');")"
[[ "$other" == 0 ]] || { echo "BLOQUEADO: $other linha(s) com tipo diferente de Entradas/Saídas/Saidas; requer decisão manual." >&2; exit 1; }
TIPOS="$(sql "SELECT COALESCE(GROUP_CONCAT(Nr),'') FROM Extracto WHERE \`Tipo de Movimento\` $BIN = 'Saidas' AND account_id IS NULL AND operation_key IS NULL;")"
[[ "$(sql "SELECT COUNT(*) FROM Extracto WHERE \`Tipo de Movimento\` $BIN = 'Saidas' AND NOT (account_id IS NULL AND operation_key IS NULL);")" == 0 ]] || {
  echo 'BLOQUEADO: há "Saidas" em linhas já ligadas a contas.' >&2; exit 1; }
if [[ -n "$NR" ]]; then
  echo "Linha a corrigir:"
  sql "SELECT CONCAT('Nr ',Nr,' | ',\`Data da Operação\`,' | ',\`Tipo de Movimento\`,' | valor actual ',Valor,' | ',COALESCE(\`Descrição\`,'')) FROM Extracto WHERE Nr=$NR;"
  [[ "$(sql "SELECT COUNT(*) FROM Extracto WHERE Nr=$NR AND account_id IS NULL AND operation_key IS NULL;")" == 1 ]] || {
    echo 'BLOQUEADO: a linha não existe ou não é histórica.' >&2; exit 1; }
  OLDV="$(sql "SELECT CAST(Valor AS CHAR) FROM Extracto WHERE Nr=$NR;")"
  echo "Valor: $OLDV -> $NEWV"
fi
[[ -n "$TIPOS" || -n "$NR" ]] || { echo 'Nada a corrigir.'; exit 0; }
[[ -z "$TIPOS" ]] || echo "Tipo 'Saidas' -> 'Saídas' nas linhas: $TIPOS"
echo 'Datas, descrições, contratos e restantes valores NÃO são alterados.'
read -r -p 'Confirma? Digite CORRIGIR-EXTRACTO: ' a
[[ "$a" == CORRIGIR-EXTRACTO ]] || { echo 'Cancelado. Nada foi alterado.'; exit 0; }
bash deploy/backup-before-auth.sh
mkdir -p "$state_dir"
: > "$state.tmp"; chmod 600 "$state.tmp"
stmts="START TRANSACTION;"
if [[ -n "$TIPOS" ]]; then
  for n in ${TIPOS//,/ }; do printf 'tipo\t%s\tSaidas\n' "$n" >> "$state.tmp"; done
  stmts+=" UPDATE Extracto SET \`Tipo de Movimento\`='Saídas' WHERE Nr IN ($TIPOS) AND \`Tipo de Movimento\` $BIN = 'Saidas' AND account_id IS NULL AND operation_key IS NULL;"
fi
if [[ -n "$NR" ]]; then
  printf 'valor\t%s\t%s\n' "$NR" "$OLDV" >> "$state.tmp"
  stmts+=" UPDATE Extracto SET Valor=$NEWV WHERE Nr=$NR AND account_id IS NULL AND operation_key IS NULL;"
fi
sql "$stmts COMMIT;" >/dev/null || { rm -f "$state.tmp"; echo 'FALHOU: nada alterado.' >&2; exit 1; }
mv "$state.tmp" "$state"
echo 'A verificar pela API se as linhas passaram a ser legíveis...'
"${APP[@]}" exec -T -e NRS="${TIPOS}${TIPOS:+${NR:+,}}${NR}" api-preview php -r '
require "/srv/cis/vendor/autoload.php";
$app=require "/srv/cis/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$ok=true;
foreach(\Illuminate\Support\Facades\DB::table("Extracto")->whereIn("Nr",array_map("intval",explode(",",getenv("NRS"))))->get(["Nr","Tipo de Movimento","Valor"]) as $r){
 $v=(string)$r->Valor; $t=(string)$r->{"Tipo de Movimento"};
 $good=in_array($t,["Entradas","Saídas"],true) && preg_match("/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D",$v)===1;
 echo "Nr ".$r->Nr.": ".$t." | ".$v." => ".($good?"OK":"AINDA INVÁLIDO")."\n"; $ok=$ok&&$good; }
exit($ok?0:4);
' || { echo 'ATENÇÃO: alguma linha continua ilegível. Pode reverter com --reverter.' >&2; exit 1; }
echo "CORRECÇÃO CONCLUÍDA. Estado: $state"
echo 'Próximo: bash deploy/assign-legacy-extracto-to-numerario.sh'
