#!/usr/bin/env bash
# Diagnostico somente leitura dos erros MySQL 1054 do Laravel.
# Mostra apenas o identificador da coluna desconhecida, nunca SQL ou dados pessoais.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'ERRO: deploy/.env ausente.' >&2; exit 1; }
COMPOSE=(sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml)
echo '=== ERROS SQL 1054: coluna nao reconhecida ==='
"${COMPOSE[@]}" exec -T api-preview php -r '
$files=glob("/srv/cis/storage/logs/*.log")?:[];
usort($files, fn($a,$b)=>filemtime($a)<=>filemtime($b));
$hits=[];
foreach(array_slice($files,-3) as $file) {
 $f=@fopen($file,"rb");if(!$f)continue;
 $size=@filesize($file);
 if($size>5000000){fseek($f,-5000000,SEEK_END);fgets($f);}
 while(($line=fgets($f))!==false){
  if(!preg_match("/^\\[([^]]+)\\].*?\\.ERROR:\\s*(.*)$/",$line,$m))continue;
  if(!str_contains($m[2],"SQLSTATE[42S22]")||!str_contains($m[2],"1054"))continue;
  $column="NAO_EXTRAIDA";
  if(preg_match("/Unknown column [\\x27\\x60]([^\\x27\\x60]+)[\\x27\\x60]/i",$m[2],$c)){
    // A mensagem indica um nome de coluna SQL (sem imprimir consultas).
    $column=preg_replace("/[^\\p{L}\\p{N} _%.\\/-]/u","?",mb_substr($c[1],0,100));
  }
  $hits[]=$m[1]." | MySQL 1054 | coluna=".$column;
 }
 fclose($f);
}
if(!$hits){echo "Nenhum erro 1054 nos logs acessiveis.".PHP_EOL;exit;}
foreach(array_slice($hits,-10) as $hit)echo $hit.PHP_EOL;
'
echo '=== INTEGRIDADE: quantidades de linhas (sem dados individuais) ==='
"${COMPOSE[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --table "$MYSQL_DATABASE"' <<'SQL'
SELECT
 (SELECT COUNT(*) FROM Contractos) AS total_linhas_contractos,
 (SELECT COUNT(*) FROM Extracto) AS total_movimentos,
 (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Contractos'
  AND COLUMN_NAME='Estratégia de Cálculo') AS coluna_estrategia_correcta;
SQL
echo 'DIAGNOSTICO TERMINADO: nenhuma alteracao, nao repetir o desembolso antes da correcao.'
