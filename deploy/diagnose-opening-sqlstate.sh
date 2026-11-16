#!/usr/bin/env bash
# Read-only diagnostic: classifies recent Laravel errors without printing SQL,
# customer data, credentials or full exception messages.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: deploy/.env ausente' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
echo '=== LOG: 10 ultimos erros, classificados sem detalhes pessoais ==='
"${COMPOSE[@]}" exec -T api-preview php -r '
$files=glob("/srv/cis/storage/logs/*.log") ?: [];
usort($files,fn($a,$b)=>filemtime($a)<=>filemtime($b));
$events=[];
foreach(array_slice($files,-3) as $file){
 $size=@filesize($file);$fh=@fopen($file,"rb");if(!$fh)continue;
 if($size>3000000){fseek($fh,-3000000,SEEK_END);fgets($fh);}
 while(($line=fgets($fh))!==false){
   if(!preg_match("/^\\[([^]]+)\\].*?\\.ERROR:\\s*(.*)$/",$line,$m))continue;
   $message=$m[2];$class="OUTRO";
   if(preg_match("/SQLSTATE\\[([A-Z0-9]{5})\\]/i",$message,$s)){
     $class="SQLSTATE=".$s[1];
     if(preg_match("/(?:\\]|:|\\s)\\s*(?:\\[)?([0-9]{4})(?:\\])?(?:\\s|:)/",$message,$n))
       $class.=" MYSQL_ERRNO=".$n[1];
   } elseif(str_contains($message,"Undefined variable"))$class="PHP_VARIABLE";
   elseif(str_contains($message,"TypeError"))$class="PHP_TYPE_ERROR";
   elseif(str_contains($message,"QueryException"))$class="QUERY_EXCEPTION";
   elseif(str_contains($message,"Permission denied"))$class="FILE_PERMISSION";
   $events[]=$m[1]." | ".$class." | fingerprint=".substr(hash("sha256",$message),0,10);
   if(count($events)>100)array_shift($events);
 }
 fclose($fh);
}
if(!$events){echo "SEM_ERROS_RECENTES_VISIVEIS".PHP_EOL;exit;}
foreach(array_slice($events,-10) as $event)echo $event.PHP_EOL;
'
echo '=== Ligacao financeira e capacidade SQL (apenas leitura) ==='
"${COMPOSE[@]}" exec -T api-preview php -r '
require "/srv/cis/vendor/autoload.php";
$app=require "/srv/cis/bootstrap/app.php";
$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
try{
 $db=\\Illuminate\\Support\\Facades\\DB::connection("mysql_contract_writer");
 $user=$db->selectOne("SELECT CURRENT_USER() AS account");
 echo "DB_WRITER_LOGIN=".($user?"OK":"INDEFINIDO").PHP_EOL;
 foreach(["Clientes","Contractos","Extracto","cis_tabela_juros"] as $name){
   try{$db->table($name)->limit(1)->get();echo "SELECT_".$name."=OK".PHP_EOL;}
   catch(\\Throwable $e){
     $code=preg_match("/SQLSTATE\\[([A-Z0-9]{5})\\]/",$e->getMessage(),$m)?$m[1]:"UNKNOWN";
     echo "SELECT_".$name."=ERRO_SQLSTATE_".$code.PHP_EOL;
   }
 }
}catch(\\Throwable $e){$code=preg_match("/SQLSTATE\\[([A-Z0-9]{5})\\]/",$e->getMessage(),$m)?$m[1]:"UNKNOWN";echo "CONEXAO_WRITER=ERRO_SQLSTATE_".$code.PHP_EOL;exit(1);}
'
echo '=== FIM: NENHUMA escrita, nao repetir desembolso ==='
