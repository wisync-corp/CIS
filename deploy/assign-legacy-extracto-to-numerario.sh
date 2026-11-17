#!/usr/bin/env bash
# CAPTA: atribuir os movimentos HISTÓRICOS do Extracto (account_id NULL) à conta
# de Numerário activa, para que o seu saldo passe a reflectir o histórico.
#
# - Só altera Extracto.account_id (NULL -> id do Numerário). Valores, datas,
#   tipos, descrições e operation_key NÃO são tocados.
# - Ajusta o marcador opening_extracto_nr da conta para 0 e, se o histórico
#   começar antes da data de corte, recua cut_at para o primeiro movimento.
# - Tudo numa transacção InnoDB com verificações; qualquer falha => ROLLBACK.
# - Guarda estado para reverter: deploy/revert-legacy-extracto-numerario.sh
#
# A análise inicial é só de leitura. Nada é gravado sem a confirmação escrita.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env ausente.' >&2; exit 1; }
sudo -v
exec 9>/tmp/cis-legacy-numerario.lock
flock -n 9 || { echo 'BLOQUEADO: operação concorrente.' >&2; exit 1; }
DB=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml)
APP=(sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
sql(){ printf '%s\n' "$1" | timeout 120 "${DB[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names "$MYSQL_DATABASE"'; }
state_dir="$HOME/cis-private/cash-verification"
state="$state_dir/legacy-numerario-assignment.state"
[[ ! -e "$state" ]] || { echo "BLOQUEADO: já existe uma atribuição registada ($state). Reverta antes de repetir." >&2; exit 1; }

echo '=== CAPTA | HISTÓRICO DO EXTRACTO → CONTA NUMERÁRIO (análise, só leitura) ==='
[[ "$(sql 'SELECT DATABASE();')" == cis ]] || exit 1
[[ "$(sql "SELECT COUNT(*) FROM cis_cash_accounts WHERE type='numerario' AND status='activa';")" == 1 ]] || {
  echo 'BLOQUEADO: tem de existir exactamente UMA conta de numerário activa.' >&2; exit 1; }
IFS=$'\t' read -r ACC CODE NAME OLDNR OLDCUT OPENING < <(sql "SELECT id,code,name,opening_extracto_nr,cut_at,opening_balance FROM cis_cash_accounts WHERE type='numerario' AND status='activa';")
echo "Conta destino: $CODE · $NAME (id $ACC) | saldo inicial $OPENING | corte $OLDCUT | marcador Nr $OLDNR"
[[ "$OPENING" =~ ^0+(\.0+)?$ ]] || {
  echo "BLOQUEADO: o saldo inicial do Numerário é $OPENING. Com histórico atribuído, o saldo inicial deve ser 0 (o histórico substitui-o)." >&2; exit 1; }
if [[ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_cash_daily_closures';")" == 1 ]]; then
  [[ "$(sql "SELECT COUNT(*) FROM cis_cash_daily_closures WHERE account_id=$ACC;")" == 0 ]] || {
    echo 'BLOQUEADO: já existem fechos desta conta; são fotografias imutáveis e ficariam incoerentes.' >&2; exit 1; }
fi
[[ "$(sql "SELECT COUNT(*) FROM Extracto WHERE account_id=$ACC AND Nr<=$OLDNR;")" == 0 ]] || {
  echo 'BLOQUEADO: há linhas da conta abaixo do marcador; estado inesperado.' >&2; exit 1; }
IFS=$'\t' read -r N MAXNR SUMNR < <(sql 'SELECT COUNT(*),COALESCE(MAX(Nr),0),COALESCE(SUM(Nr),0) FROM Extracto WHERE account_id IS NULL;')
[[ "$N" -ge 1 ]] || { echo 'Não há movimentos históricos sem conta. Nada a fazer.'; exit 0; }

# Análise pelo MESMO caminho de leitura da API (PDO/Laravel), com a mesma regra de cêntimos.
analysis="$("${APP[@]}" exec -T -e MAXNR="$MAXNR" -e CUT="$OLDCUT" api-preview php -r '
require "/srv/cis/vendor/autoload.php";
$app=require "/srv/cis/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$max=(int)getenv("MAXNR"); $cut=substr((string)getenv("CUT"),0,10);
$rows=\Illuminate\Support\Facades\DB::table("Extracto")->whereNull("account_id")->where("Nr","<=",$max)
  ->orderBy("Data da Operação")->orderBy("Nr")->get(["Nr","Tipo de Movimento","Valor","Data da Operação"]);
$types=[];$bad=0;$badEx=[];$net=0;$in=0;$out=0;$min=PHP_INT_MAX;$minDate="";$before=0;$zero=0;$first=null;$last=null;
foreach($rows as $r){
  $t=(string)$r->{"Tipo de Movimento"}; $types[$t]=($types[$t]??0)+1;
  $v=(string)$r->Valor; $d=(string)$r->{"Data da Operação"}; $d10=substr($d,0,10);
  if($d10===""||str_starts_with($d10,"0000"))$zero++; else { $first??=$d; $last=$d; if($d10<$cut)$before++; }
  if(preg_match("/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D",$v)!==1){$bad++; if(count($badEx)<5)$badEx[]="Nr ".$r->Nr."=".$v; continue;}
  [$a,$b]=array_pad(explode(".",$v,2),2,""); $c=(int)$a*100+(int)str_pad($b,2,"0");
  if($t==="Entradas"){$net+=$c;$in+=$c;} elseif($t==="Saídas"){$net-=$c;$out+=$c;}
  if($net<$min){$min=$net;$minDate=$d10;}
}
$m=fn($c)=>($c<0?"-":"").number_format(intdiv(abs($c),100),0,","," ").",".str_pad((string)(abs($c)%100),2,"0",STR_PAD_LEFT);
$unknown=array_sum(array_diff_key($types,["Entradas"=>1,"Saídas"=>1]));
echo "Linhas: ".count($rows)." | primeira data: ".($first??"-")." | última: ".($last??"-")."\n";
foreach($types as $k=>$n) echo "  Tipo \"$k\": $n\n";
echo "Entradas: ".$m($in)." MT | Saídas: ".$m($out)." MT\n";
echo "SALDO RESULTANTE DO HISTÓRICO: ".$m($net)." MT\n";
echo "Saldo mínimo ao longo do tempo: ".$m($min==PHP_INT_MAX?0:$min)." MT em ".($minDate?:"-")."\n";
echo "Movimentos antes da data de corte ($cut): $before | datas vazias/zero: $zero\n";
if($bad) echo "VALORES FORA DO FORMATO DO CIS: $bad (ex.: ".implode("; ",$badEx).")\n";
echo "@@ ".$bad." ".$unknown." ".$net." ".($first?substr($first,0,19):"-")."\n";
')"
printf '%s\n' "$analysis" | grep -v '^@@ '
read -r _ BAD UNKNOWN NET FIRST < <(printf '%s\n' "$analysis" | grep '^@@ ')
[[ "$BAD" == 0 ]] || { echo 'BLOQUEADO: existem valores que o CIS não consegue converter em cêntimos; o saldo da conta deixaria de abrir. Corrigir primeiro.' >&2; exit 1; }
[[ "$UNKNOWN" == 0 ]] || { echo 'BLOQUEADO: existem tipos de movimento diferentes de Entradas/Saídas.' >&2; exit 1; }
(( NET >= 0 )) || { echo 'BLOQUEADO: o saldo resultante seria negativo. Rever o histórico antes de atribuir.' >&2; exit 1; }
NEWCUT="$OLDCUT"
if [[ "$FIRST" != "-" && "$FIRST" < "${OLDCUT:0:19}" ]]; then NEWCUT="$FIRST"; fi
echo
echo "Serão atribuídas $N linhas (Nr até $MAXNR) à conta $CODE."
echo "Marcador de abertura: $OLDNR -> 0 | Data de corte: $OLDCUT -> $NEWCUT | Saldo inicial mantém-se 0."
echo 'Nenhum valor, data, tipo ou descrição será alterado. A Conta Principal não é afectada.'
echo 'ATENÇÃO: movimentos que o sistema Excel antigo grave DEPOIS disto continuam sem conta.'
read -r -p 'Confirma? Digite ATRIBUIR-HISTORICO-NUMERARIO: ' answer
[[ "$answer" == 'ATRIBUIR-HISTORICO-NUMERARIO' ]] || { echo 'Cancelado. Nada foi alterado.'; exit 0; }

bash deploy/backup-before-auth.sh
mkdir -p "$state_dir"
printf 'ACC=%s\nOLDNR=%s\nOLDCUT=%q\nMAXNR=%s\nN=%s\nSUMNR=%s\nAT=%s\n' "$ACC" "$OLDNR" "$OLDCUT" "$MAXNR" "$N" "$SUMNR" "$(date -Is)" > "$state.tmp"
chmod 600 "$state.tmp"

# Transacção única. Cada INSERT em chk falha (CHECK) se a condição for falsa;
# o cliente mysql pára no primeiro erro e a ligação fecha => ROLLBACK automático.
if ! sql "CREATE TEMPORARY TABLE chk(ok TINYINT NOT NULL, CHECK (ok=1));
START TRANSACTION;
SELECT id FROM cis_cash_accounts WHERE id=$ACC FOR UPDATE;
INSERT INTO chk SELECT (status='activa' AND type='numerario' AND opening_extracto_nr=$OLDNR) FROM cis_cash_accounts WHERE id=$ACC;
INSERT INTO chk SELECT (COUNT(*)=$N AND COALESCE(SUM(Nr),0)=$SUMNR) FROM Extracto WHERE account_id IS NULL AND Nr<=$MAXNR;
UPDATE Extracto SET account_id=$ACC WHERE account_id IS NULL AND Nr<=$MAXNR;
INSERT INTO chk SELECT (ROW_COUNT()=$N);
UPDATE cis_cash_accounts SET opening_extracto_nr=0, cut_at='$NEWCUT', updated_at=NOW() WHERE id=$ACC AND opening_extracto_nr=$OLDNR;
INSERT INTO chk SELECT (ROW_COUNT()=1);
INSERT INTO chk SELECT (COUNT(*)=$N) FROM Extracto WHERE account_id=$ACC AND Nr<=$MAXNR AND operation_key IS NULL;
COMMIT;" >/dev/null; then
  rm -f "$state.tmp"
  echo 'FALHOU: transacção revertida (ROLLBACK). Nada foi alterado.' >&2; exit 1
fi
mv "$state.tmp" "$state"

echo 'A confirmar o saldo pela API...'
"${APP[@]}" exec -T -e ACC="$ACC" api-preview php -r '
require "/srv/cis/vendor/autoload.php";
$app=require "/srv/cis/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$a=\Illuminate\Support\Facades\DB::table("cis_cash_accounts")->where("id",(int)getenv("ACC"))->first();
$s=0; foreach(\Illuminate\Support\Facades\DB::table("Extracto")->where("account_id",$a->id)->where("Nr",">",$a->opening_extracto_nr)->get(["Tipo de Movimento","Valor"]) as $r){
 [$x,$y]=array_pad(explode(".",(string)$r->Valor,2),2,""); $c=(int)$x*100+(int)str_pad($y,2,"0");
 $s+=$r->{"Tipo de Movimento"}==="Entradas"?$c:-$c; }
echo "Saldo disponível do ".$a->code." (como a interface calcula): ".number_format($s/100,2,","," ")." MT\n";
'
echo "HISTÓRICO ATRIBUÍDO AO NUMERÁRIO. Estado para reversão: $state"
echo 'Para desfazer: bash deploy/revert-legacy-extracto-numerario.sh'
