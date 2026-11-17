<?php
// Smoke SOMENTE com SQLite efémero: teste do relatório de Caixa por período.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\CashReportController;
use App\Models\User;

if(DB::getDriverName()!=='sqlite')throw new RuntimeException('Relatório: apenas SQLite isolado.');
$route=collect(Route::getRoutes()->getRoutes())->first(fn($r)=>$r->uri()==='api/cis/caixa/relatorio');
if(!$route || !in_array('GET',$route->methods(),true) || !collect($route->gatherMiddleware())->contains('auth:sanctum'))
 throw new RuntimeException('Relatório sem rota GET autenticada.');
$_ENV['CIS_PRIVATE_PREVIEW']='true';$_SERVER['CIS_PRIVATE_PREVIEW']='true';putenv('CIS_PRIVATE_PREVIEW=true');
$db=DB::connection();
$db->beginTransaction();
try {
 $account=$db->table('cis_cash_accounts')->insertGetId([
  'code'=>'REPORT-A','name'=>'Conta de relatório','type'=>'numerario',
  'currency'=>'MZN','status'=>'activa','opening_balance'=>'100.00',
  'cut_at'=>'2026-11-17 00:00:00','opening_extracto_nr'=>0,
  'created_by'=>1,'validated_by'=>1,'validated_at'=>now(),
  'evidence_reference'=>'TEST-NOT-REAL','created_at'=>now(),'updated_at'=>now(),
 ]);
 $other=$db->table('cis_cash_accounts')->insertGetId([
  'code'=>'REPORT-B','name'=>'Conta de destino','type'=>'banco',
  'currency'=>'MZN','status'=>'activa','opening_balance'=>'100.00',
  'cut_at'=>'2026-11-17 00:00:00','opening_extracto_nr'=>0,
  'created_by'=>1,'validated_by'=>1,'validated_at'=>now(),
  'evidence_reference'=>'TEST-NOT-REAL','created_at'=>now(),'updated_at'=>now(),
 ]);
 $row=fn($a,$key,$type,$value,$description)=>[
  'account_id'=>$a,'operation_key'=>$key,
  'Tipo de Movimento'=>$type,'Valor'=>$value,
  'Data da Operação'=>'2026-11-17','Descrição'=>$description,
  'Cliente'=>'CAIXA GERAL','Contracto'=>'','Parcela'=>0,'usuario'=>'1',
 ];
 $db->table('Extracto')->insert($row($account,'cash:00000000-0000-4000-8000-000000000301:1','Entradas','10.00','[CAIXA:OPERACOES] receita | REF:TEST-REC-1'));
 $db->table('Extracto')->insert($row($account,'cash:00000000-0000-4000-8000-000000000302:1','Saídas','3.00','[CAIXA:OPERACOES] despesa | REF:TEST-REC-2'));
 $db->table('Extracto')->insert($row($account,'cash:00000000-0000-4000-8000-000000000303:1','Saídas','4.00','[TRANSFER SAIDA 1>2] envio | REF:TRANS-001'));
 $db->table('Extracto')->insert($row($other,'cash:00000000-0000-4000-8000-000000000303:2','Entradas','4.00','[TRANSFER ENTRADA 1>2] entrada | REF:TRANS-001'));
 $u=new User();$u->id=1;$u->active=true;$u->role='administrador';
 $request=Request::create('/api/cis/caixa/relatorio','GET',[
   'from'=>'2026-11-17','to'=>'2026-11-17',
 ]);
 $request->setUserResolver(static fn()=>$u);
 $json=(new CashReportController)->index($request)->getData(true);
 foreach(['entries'=>'10.00','exits'=>'3.00','net_external'=>'7.00',
          'internal_in'=>'4.00','internal_out'=>'4.00','internal_net'=>'0.00'] as $k=>$value){
  if($json[$k]!==$value)throw new RuntimeException("Relatório $k esperado $value, obtido ".$json[$k]);
 }
 if($json['movements_count']!==4)throw new RuntimeException('Contagem de movimentos inesperada.');
 echo "OK: relatório separa entradas e saídas efectivas de transferências internas sem inflacionar receitas.\n";
} finally {$db->rollBack();}
if($db->table('Extracto')->count()!==0)throw new RuntimeException('Fixture financeira não foi totalmente revertida.');
