<?php
// Fechos em SQLite descartável de CI: nenhum movimento real, tudo revertido.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\CashClosureController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Validation\ValidationException;

if(DB::getDriverName()!=='sqlite')throw new RuntimeException('BLOQUEADO: só SQLite efémero.');
if(!Schema::hasTable('cis_cash_daily_closures'))throw new RuntimeException('Migração fechos não executada.');
config(['database.connections.mysql_cash_closures'=>config('database.connections.sqlite')]);
DB::purge('mysql_cash_closures');
foreach(['CIS_PRIVATE_PREVIEW','CIS_CASH_DAILY_CLOSE_APPROVED'] as $flag){
 $_ENV[$flag]='true';$_SERVER[$flag]='true';putenv($flag.'=true');
}
function checkClose(bool $ok,string $reason):void {
 if(!$ok)throw new RuntimeException('FECHO DIARIO CI: '.$reason);
}
$routes=collect(Route::getRoutes()->getRoutes());
foreach(['GET','POST'] as $method){
 $found=$routes->first(fn($r)=>$r->uri()==='api/cis/caixa/fecho-diario'
   && in_array($method,$r->methods(),true));
 checkClose($found!==null && collect($found->gatherMiddleware())->contains('auth:sanctum'),
   'rota autenticada '.$method);
}
$user=new User();$user->id=101;$user->role='administrador';$user->active=true;
function closeRequest(CashClosureController $ctl,string $method,array $data):array {
 global $user;
 $request=Request::create('/api/cis/caixa/fecho-diario',$method==='store'?'POST':'GET',$data);
 $request->setUserResolver(static fn()=>$user);
 return $ctl->$method($request)->getData(true);
}
function rejectClose(callable $func,int $http,string $context):void{
 try{$func();throw new RuntimeException('Não foi bloqueado: '.$context);}
 catch(HttpException $e){checkClose($e->getStatusCode()===$http,'Código incorrecto '.$context);}
}
$db=DB::connection();
$db->beginTransaction();
try{
 $date=now('Africa/Maputo')->format('Y-m-d');
 $account=$db->table('cis_cash_accounts')->insertGetId([
  'code'=>'FECHO-CI','name'=>'Conta de fecho CI','type'=>'numerario','currency'=>'MZN',
  'status'=>'activa','opening_balance'=>'100.00','cut_at'=>$date.' 08:00:00',
  'opening_extracto_nr'=>0,'created_by'=>101,'validated_by'=>101,'validated_at'=>now(),
  'evidence_reference'=>'CI-NON-REAL','created_at'=>now(),'updated_at'=>now(),
 ]);
 $db->table('Extracto')->insert([
  'account_id'=>$account,'operation_key'=>'cash:00000000-0000-4000-8000-000000000951:1',
  'Tipo de Movimento'=>'Entradas','Valor'=>'25.00','Data da Operação'=>$date,
  'Descrição'=>'[CAIXA:TEST] entrada','Cliente'=>'CAIXA GERAL','Contracto'=>'',
  'Parcela'=>0,'usuario'=>'101',
 ]);
 $db->table('Extracto')->insert([
  'account_id'=>$account,'operation_key'=>'cash:00000000-0000-4000-8000-000000000952:1',
  'Tipo de Movimento'=>'Saídas','Valor'=>'10.00','Data da Operação'=>$date,
  'Descrição'=>'[CAIXA:TEST] saida','Cliente'=>'CAIXA GERAL','Contracto'=>'',
  'Parcela'=>0,'usuario'=>'101',
 ]);
 $controller=new CashClosureController();
 $peek=closeRequest($controller,'show',['date'=>$date,'account_id'=>$account]);
 checkClose($peek['snapshot']['expected_balance']==='115.00','saldo real computado');
 checkClose($peek['can_confirm']===true && $peek['existing']===null,
   'prévia sem fecho e não persistente');
 checkClose($db->table('cis_cash_daily_closures')->count()===0,'prévia sem inserção');
 $payload=['date'=>$date,'account_id'=>$account,'observed_balance'=>'112.00',
  'verification_method'=>'contagem_numerario','evidence_reference'=>'CI-CONTAGEM-2026',
  'difference_reason'=>'Divergência física documentada','verified_observed'=>true];
 $saved=closeRequest($controller,'store',$payload);
 checkClose($saved['recorded']===true && $saved['difference_amount']==='-3.00',
   'diferença guardada como -3 MT sem ajustar o Extracto');
 checkClose($db->table('Extracto')->count()===2,'nenhum movimento financeiro criado');
 $close=closeRequest($controller,'show',['date'=>$date,'account_id'=>$account]);
 checkClose($close['can_confirm']===false && $close['existing']!==null,
   'fecho único por conta e data');
 rejectClose(fn()=>closeRequest($controller,'store',$payload),409,'fecho duplicado');
 checkClose($db->table('cis_cash_daily_closures')->count()===1,'único registo de controlo');
 $_ENV['CIS_CASH_DAILY_CLOSE_APPROVED']='false';
 $_SERVER['CIS_CASH_DAILY_CLOSE_APPROVED']='false';
 putenv('CIS_CASH_DAILY_CLOSE_APPROVED=false');
 rejectClose(fn()=>closeRequest($controller,'store',$payload),503,'flag independente');
 echo "OK: fecho único, divergência justificada, prévia, imutabilidade, zero movimentos e flag independente.\n";
} finally {$db->rollBack();}
checkClose($db->table('cis_cash_daily_closures')->count()===0,'rollback da fixture');
checkClose($db->table('Extracto')->count()===0,'rollback do Extracto');
