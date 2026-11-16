#!/usr/bin/env bash
# Diagnóstico sem escrita de falhas de abertura/desembolso.
# Não imprime nomes de clientes, credenciais, SQL completo nem logs brutos.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: deploy/.env em falta.' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
echo '=== Estado dos serviços (sem alteração) ==='
"${COMPOSE[@]}" ps api-preview web-preview mysql
echo '=== Contagens financeiras (sem valores individuais) ==='
"${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --table "$MYSQL_DATABASE"' <<'SQL'
SELECT (SELECT COUNT(*) FROM Contractos) AS linhas_contratos,
       (SELECT COUNT(*) FROM Extracto) AS linhas_extracto,
       (SELECT COUNT(DISTINCT `Nr do Contracto`) FROM Contractos) AS contratos_distintos;
SELECT COUNT(*) AS desembolsos_registados,
       MAX(Nr) AS ultimo_id_extracto_desembolso
FROM Extracto
WHERE `Tipo de Movimento`='Saídas' AND `Descrição`='Desembolso de Empréstimo';
SQL
echo '=== Indicadores dos últimos erros Laravel (sem mensagens ou SQL completos) ==='
"${COMPOSE[@]}" exec -T api-preview php -r '
$files=glob("/srv/cis/storage/logs/*.log")?:[];
usort($files,fn($a,$b)=>filemtime($a)<=>filemtime($b));
$files=array_slice($files,-3);
$events=[];
foreach($files as $file){
  $f=@fopen($file,"rb"); if(!$f)continue;
  $size=filesize($file); if($size>2000000)fseek($f,-2000000,SEEK_END);
  while(($line=fgets($f))!==false){
    if(!preg_match("/^\\[([^]]+)\\]\\s+[^.]+\\.ERROR:\\s+(.*)$/",$line,$m))continue;
    $kind="ERRO_APLICACAO";
    if(preg_match("/SQLSTATE\\[([A-Z0-9]+)\\].*?\\(([0-9]+)\\)/",$m[2],$s))$kind="MYSQL_SQLSTATE_".$s[1]."_CODIGO_".$s[2];
    elseif(str_contains($m[2],"SQLSTATE"))$kind="MYSQL_SQLSTATE";
    elseif(preg_match("/Undefined variable \\$([A-Za-z_]+)/",$m[2],$s))$kind="PHP_VARIAVEL_".$s[1];
    elseif(str_contains($m[2],"TypeError"))$kind="PHP_TYPE_ERROR";
    elseif(str_contains($m[2],"Permission denied"))$kind="PERMISSAO_FICHEIRO";
    elseif(str_contains($m[2],"Call to undefined"))$kind="PHP_CHAMADA_INDEFINIDA";
    $events[]=["data"=>$m[1],"tipo"=>$kind,"assinatura"=>substr(hash("sha256",$m[2]),0,10)];
    if(count($events)>100)array_shift($events);
  }
  fclose($f);
}
if(!$events){echo "Sem entradas ERROR encontradas nos logs acessíveis.\\n";exit;}
foreach(array_slice($events,-10) as $event)
 echo $event["data"]." | ".$event["tipo"]." | ref=".$event["assinatura"]."\\n";
'
echo '=== FIM: diagnóstico apenas de leitura; não repetir desembolso sem confirmar estado ==='
