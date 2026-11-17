<?php
// Teste de estornos sobre RESTAURO LEGADO MySQL DESCARTÁVEL.
// Não executar numa base operacional; todo o DML fica em transacção revertida.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\CashPostingController;
use App\Http\Controllers\Api\CashReversalController;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
$host=(string)config('database.connections.mysql.host');
if(DB::getDriverName()!=='mysql'||$host!=='cash-real-mysql'
 ||!filter_var(env('CIS_ISOLATED_REHEARSAL',false),FILTER_VALIDATE_BOOLEAN)){
 throw new RuntimeException('BLOQUEADO: ensaio exclusivamente em cash-real-mysql isolado.');
}
foreach(['CIS_PRIVATE_PREVIEW','CIS_CASH_WRITES_APPROVED','CIS_CASH_LEDGER_POSTING_APPROVED',
 'CIS_CASH_REVERSALS_APPROVED'] as $flag){$_ENV[$flag]='true';$_SERVER[$flag]='true';putenv($flag.'=true');}
$user=User::query()->where('role','administrador')->where('active',true)->first();
if(!$user)throw new RuntimeException('Restauro sem administrador activo.');
$db=DB::connection('mysql_cash_ledger');
if($db->getDatabaseName()!=='cis')throw new RuntimeException('Base inesperada.');
function testReversal(bool $ok,string $why):void{
 if(!$ok)throw new RuntimeException('MySQL ESTORNO: '.$why);
}
function mysqlPost(object $controller,string $method,array $data,User $user,int $nr=0):array{
 $r=Request::create('/api/cis/caixa','POST',$data);
 $r->setUserResolver(static fn()=>$user);
 return ($controller instanceof CashReversalController
  ?$controller->$method($r,$nr):$controller->$method($r))->getData(true);
}
$initialRows=$db->table('Extracto')->count();
$initialTotal=(string)$db->table('Extracto')->sum('Valor');
$initialAccounts=$db->table('cis_cash_accounts')->count();
$db->beginTransaction();
try{
 $name='CI-ESTORNO-'.bin2hex(random_bytes(4));
 $cut=now('Africa/Maputo')->format('Y-m-d H:i:s');
 $opening=(int)($db->table('Extracto')->max('Nr')??0);
 $make=function(string $suffix,string $balance)use($db,$name,$cut,$opening,$user){
  return $db->table('cis_cash_accounts')->insertGetId([
   'code'=>$name.$suffix,'name'=>'Conta temporária ensaio estorno',
   'type'=>'numerario','currency'=>'MZN','status'=>'activa',
   'opening_balance'=>$balance,'cut_at'=>$cut,'opening_extracto_nr'=>$opening,
   'evidence_reference'=>'TESTE-ISOLADO-NÃO-REAL',
   'created_by'=>$user->id,'validated_by'=>$user->id,'validated_at'=>$cut,
   'created_at'=>now(),'updated_at'=>now(),
  ]);
 };
 $a=$make('A','100.00');$b=$make('B','25.00');
 $posting=new CashPostingController();$reversing=new CashReversalController();
 $record=mysqlPost($posting,'record',[
  'account_id'=>$a,'type'=>'saida','amount'=>'30.00',
  'category'=>'Despesas','description'=>'Saída controlada em ensaio',
  'evidence_reference'=>'ENSAIO-REV-001',
  'idempotency_key'=>'00000000-0000-4000-8000-000000000801',
 ],$user);
 $undo=['reason'=>'Erro documental confirmado na origem',
  'evidence_reference'=>'COMPROV-ESTORNO-801','confirmation_phrase'=>'CONFIRMO-ESTORNO-REAL'];
 $pre=mysqlPost($reversing,'preview',[],$user,$record['extracto_nr']);
 testReversal(!$pre['already_reversed'] && $pre['mode']==='single','prévia de entrada/saída');
 $done=mysqlPost($reversing,'reverse',$undo,$user,$record['extracto_nr']);
 testReversal($done['reversed']&&count($done['reversal_extracto_nrs'])===1,'estorno efectivo');
 try{
  mysqlPost($reversing,'reverse',$undo,$user,$record['extracto_nr']);
  throw new RuntimeException('Estorno repetido não foi bloqueado.');
 }catch(HttpException $e){testReversal($e->getStatusCode()===409,'HTTP 409 em repetição');}
 $transfer=mysqlPost($posting,'transfer',[
  'source_account_id'=>$a,'destination_account_id'=>$b,
  'amount'=>'10.00','description'=>'Transferência real de ensaio isolado',
  'evidence_reference'=>'TRANSFER-CI-001',
  'confirmed_source_debit'=>true,'confirmed_destination_credit'=>true,
  'idempotency_key'=>'00000000-0000-4000-8000-000000000802',
 ],$user);
 $reverseTransfer=mysqlPost($reversing,'reverse',$undo,$user,$transfer['destination_extracto_nr']);
 testReversal(count($reverseTransfer['reversal_extracto_nrs'])===2,'estorno de transferência atómico');
 $reversalRows=$db->table('Extracto')->whereIn('Nr',$reverseTransfer['reversal_extracto_nrs'])->get();
 testReversal($reversalRows->count()===2,'duas linhas de estorno');
 testReversal($reversalRows->sum('Valor')==20,'duas pernas do mesmo valor');
 echo "OK: MySQL legado permitiu estorno de saída e transferência (2 pernas) e impediu repetição.\n";
}finally{$db->rollBack();}
testReversal($db->table('Extracto')->count()===$initialRows,'total de linhas restaurado');
testReversal((string)$db->table('Extracto')->sum('Valor')===$initialTotal,'valor total restaurado');
testReversal($db->table('cis_cash_accounts')->count()===$initialAccounts,'contas de teste revertidas');
echo "ENSAIO DE ESTORNOS MySQL APROVADO. Todas as operações experimentais revertidas.\n";
