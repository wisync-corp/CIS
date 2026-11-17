<?php
// Estornos reais só em SQLite descartável de CI, revertidos integralmente.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Models\User;
use App\Http\Controllers\Api\CashPostingController;
use App\Http\Controllers\Api\CashReversalController;
use Symfony\Component\HttpKernel\Exception\HttpException;

if(DB::getDriverName()!=='sqlite')throw new RuntimeException('BLOQUEADO: testar estornos apenas em SQLite efémero.');
if(!Schema::hasColumn('Extracto','operation_key'))throw new RuntimeException('operation_key em falta.');
config(['database.connections.mysql_cash_ledger'=>config('database.connections.sqlite')]);
DB::purge('mysql_cash_ledger');
foreach(['CIS_PRIVATE_PREVIEW','CIS_CASH_WRITES_APPROVED',
 'CIS_CASH_LEDGER_POSTING_APPROVED','CIS_CASH_REVERSALS_APPROVED'] as $flag){
 $_ENV[$flag]='true';$_SERVER[$flag]='true';putenv($flag.'=true');
}
function revAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('ESTORNO CI: '.$message);}
$routes=collect(Route::getRoutes()->getRoutes());
foreach([['estornos/{nr}/previsualizar','GET'],['estornos/{nr}','POST']] as [$path,$method]){
 $route=$routes->first(fn($r)=>$r->uri()==='api/cis/caixa/'.$path);
 revAssert($route!==null && in_array($method,$route->methods(),true),'rota '.$path);
 revAssert(collect($route->gatherMiddleware())->contains('auth:sanctum'),'rota sem autenticação');
}
$user=new User();$user->id=101;$user->role='administrador';$user->active=true;
function revCall(object $controller,string $method,array $data,int $nr=0):array{
 global $user;
 $req=Request::create('/api/cis/caixa','POST',$data);
 $req->setUserResolver(static fn()=>$user);
 $resp=$controller instanceof CashReversalController
   ?$controller->$method($req,$nr)
   :$controller->$method($req);
 return $resp->getData(true);
}
function revRefused(callable $f,int $code,string $reason):void{
 try{$f();throw new RuntimeException('Sem bloqueio '.$reason);}
 catch(HttpException $e){revAssert($e->getStatusCode()===$code,'HTTP '.$reason);}
}
$db=DB::connection();
$db->beginTransaction();
try{
 $now=now('Africa/Maputo')->format('Y-m-d H:i:s');
 $account=function(string $code,string $opening)use($db,$now){
  return $db->table('cis_cash_accounts')->insertGetId([
   'code'=>$code,'name'=>'Conta Teste','type'=>'numerario','currency'=>'MZN',
   'status'=>'activa','opening_balance'=>$opening,'cut_at'=>$now,
   'opening_extracto_nr'=>0,'created_by'=>101,'validated_by'=>101,
   'validated_at'=>$now,'evidence_reference'=>'EXTRACTO-CI-NOT-REAL',
   'created_at'=>now(),'updated_at'=>now(),
  ]);
 };
 $a=$account('REV-A','100.00');$b=$account('REV-B','30.00');
 $poster=new CashPostingController();
 $reverser=new CashReversalController();
 $base=['category'=>'Operacoes','description'=>'Operação verificada para teste',
        'evidence_reference'=>'COMPROV-CI-123'];
 $entry=function(int $id,string $type,string $amount,string $suffix)use($poster,$base){
  return revCall($poster,'record',$base+[
   'account_id'=>$id,'type'=>$type,'amount'=>$amount,
   'idempotency_key'=>'00000000-0000-4000-8000-000000000'.str_pad($suffix,3,'0',STR_PAD_LEFT),
  ]);
 };
 $undo=['reason'=>'Duplicação documental comprovada','evidence_reference'=>'ESTORNO-CI-COMPROV',
  'confirmation_phrase'=>'CONFIRMO-ESTORNO-REAL'];
 $first=$entry($a,'saida','40.00','411');
 $preview=revCall($reverser,'preview',[],$first['extracto_nr']);
 revAssert($preview['consulta_apenas']===true && !$preview['already_reversed']
   && $preview['mode']==='single','prévia somente leitura');
 $count=$db->table('Extracto')->count();
 revAssert($count===1,'prévia não pode inserir movimentos');
 $done=revCall($reverser,'reverse',$undo,$first['extracto_nr']);
 revAssert($done['reversed'] && count($done['reversal_extracto_nrs'])===1,'estorno de saída');
 revAssert($db->table('Extracto')->count()===2,'original preservado + estorno');
 revRefused(fn()=>revCall($reverser,'reverse',$undo,$first['extracto_nr']),409,'estorno repetido');
 revRefused(fn()=>revCall($reverser,'reverse',$undo,$done['reversal_extracto_nrs'][0]),409,
   'estorno de estorno proibido');

 $income=$entry($b,'entrada','20.00','412');
 $expense=$entry($b,'saida','45.00','413');
 revRefused(fn()=>revCall($reverser,'reverse',$undo,$income['extracto_nr']),409,
   'estorno de entrada sem saldo suficiente');

 $transfer=function(string $amount,string $suffix)use($poster,$a,$b){
  return revCall($poster,'transfer',[
   'source_account_id'=>$a,'destination_account_id'=>$b,
   'amount'=>$amount,'description'=>'Transferência verificada para teste',
   'evidence_reference'=>'COMPROV-TRANS-CI',
   'confirmed_source_debit'=>true,'confirmed_destination_credit'=>true,
   'idempotency_key'=>'00000000-0000-4000-8000-000000000'.str_pad($suffix,3,'0',STR_PAD_LEFT),
  ]);
 };
 $movement=$transfer('10.00','414');
 $pre=revCall($reverser,'preview',[],$movement['destination_extracto_nr']);
 revAssert($pre['mode']==='transfer'&&count($pre['legs'])===2,'prévia da transferência');
 $before=$db->table('Extracto')->count();
 $db->statement("CREATE TRIGGER ci_reversal_fail BEFORE INSERT ON Extracto WHEN NEW.operation_key LIKE 'cash:reverse:transfer:%:2' BEGIN SELECT RAISE(ABORT,'CI rollback'); END");
 try{
  revCall($reverser,'reverse',$undo,$movement['source_extracto_nr']);
  throw new RuntimeException('Estorno de transferência não falhou.');
 }catch(\Illuminate\Database\QueryException $expected){}
 $db->statement('DROP TRIGGER ci_reversal_fail');
 revAssert($db->table('Extracto')->count()===$before,'falha da segunda perna reverte primeira');

 $reversed=revCall($reverser,'reverse',$undo,$movement['destination_extracto_nr']);
 revAssert(count($reversed['reversal_extracto_nrs'])===2,'duas pernas de reversão atómicas');
 revAssert($db->table('Extracto')->count()===$before+2,'sem linhas de teste residuais');
 revAssert($db->table('Extracto')->where('operation_key',
   'cash:reverse:transfer:'.$movement['source_extracto_nr'].':1')->exists(),'chave determinística');
 revRefused(fn()=>revCall($reverser,'reverse',$undo,$movement['source_extracto_nr']),409,
  'repetição estorno transferência');
 // Desligar explicitamente estornos apesar de entradas e saídas estarem activadas.
 $_ENV['CIS_CASH_REVERSALS_APPROVED']='false';$_SERVER['CIS_CASH_REVERSALS_APPROVED']='false';
 putenv('CIS_CASH_REVERSALS_APPROVED=false');
 revRefused(fn()=>revCall($reverser,'reverse',$undo,$expense['extracto_nr']),503,
  'flag independente');
 echo "OK: estorno de saída, prévia, bloqueio de duplicação, saldo insuficiente, duas pernas e rollback forçado.\n";
}finally{$db->rollBack();}
revAssert($db->table('Extracto')->count()===0,'fixture não foi revertida');
