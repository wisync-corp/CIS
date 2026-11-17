<?php
// Teste de conferência diária exclusivamente em SQLite efémero e ROLLBACK.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Models\User;
use App\Http\Controllers\Api\CashDailyController;
if(DB::getDriverName()!=='sqlite')throw new RuntimeException('Teste permitido só em SQLite descartável.');
$route=collect(Route::getRoutes()->getRoutes())->first(fn($r)=>$r->uri()==='api/cis/caixa/conferencia-diaria');
if(!$route || !in_array('GET',$route->methods(),true)
 || !collect($route->gatherMiddleware())->contains('auth:sanctum')){
 throw new RuntimeException('Falta rota GET autenticada da conferência.');
}
$_ENV['CIS_PRIVATE_PREVIEW']='true';$_SERVER['CIS_PRIVATE_PREVIEW']='true';
putenv('CIS_PRIVATE_PREVIEW=true');
$db=DB::connection();
$db->beginTransaction();
try{
 $a=$db->table('cis_cash_accounts')->insertGetId([
  'code'=>'DIARIO-A','name'=>'Conta teste A','type'=>'numerario','currency'=>'MZN',
  'status'=>'activa','opening_balance'=>'100.00','cut_at'=>'2026-11-16 10:00:00',
  'opening_extracto_nr'=>0,'created_by'=>1,'validated_by'=>1,'validated_at'=>now(),
  'evidence_reference'=>'CI-NAO-REAL','created_at'=>now(),'updated_at'=>now(),
 ]);
 $b=$db->table('cis_cash_accounts')->insertGetId([
  'code'=>'DIARIO-B','name'=>'Conta teste B','type'=>'numerario','currency'=>'MZN',
  'status'=>'activa','opening_balance'=>'25.00','cut_at'=>'2026-11-16 10:00:00',
  'opening_extracto_nr'=>0,'created_by'=>1,'validated_by'=>1,'validated_at'=>now(),
  'evidence_reference'=>'CI-NAO-REAL','created_at'=>now(),'updated_at'=>now(),
 ]);
 $row=fn($account,$key,$type,$val,$date,$desc)=>[
  'account_id'=>$account,'operation_key'=>$key,
  'Tipo de Movimento'=>$type,'Valor'=>$val,
  'Data da Operação'=>$date,'Descrição'=>$desc,'Cliente'=>'CAIXA GERAL',
  'Contracto'=>'','Parcela'=>0,'usuario'=>'1',
 ];
 $db->table('Extracto')->insert($row($a,'cash:00000000-0000-4000-8000-000000000201:1','Entradas','50.00','2026-11-16','[CAIXA:Receita] ontem | REF:PREV-1'));
 $db->table('Extracto')->insert($row($a,'cash:00000000-0000-4000-8000-000000000202:1','Saídas','20.00','2026-11-17','[CAIXA:Gasto] hoje | REF:HOJE-1'));
 $db->table('Extracto')->insert($row($a,'cash:00000000-0000-4000-8000-000000000203:1','Saídas','10.00','2026-11-17','[TRANSFER SAIDA] caixa A | REF:T-1'));
 $db->table('Extracto')->insert($row($b,'cash:00000000-0000-4000-8000-000000000203:2','Entradas','10.00','2026-11-17','[TRANSFER ENTRADA] caixa B | REF:T-1'));
 $u=new User();$u->id=1;$u->role='administrador';$u->active=true;
 $req=Request::create('/api/cis/caixa/conferencia-diaria','GET',['date'=>'2026-11-17']);
 $req->setUserResolver(static fn()=>$u);
 $result=(new CashDailyController)->index($req)->getData(true);
 if($result['totals']['opening']!=='175.00' || $result['totals']['entries']!=='10.00'
  || $result['totals']['exits']!=='30.00'|| $result['totals']['closing']!=='155.00'){
  throw new RuntimeException('Totais diários incorrectos '.json_encode($result['totals']));
 }
 $accounts=collect($result['accounts'])->keyBy('code');
 if($accounts['DIARIO-A']['closing']!=='120.00'
  || $accounts['DIARIO-B']['closing']!=='35.00'
  || $result['totals']['internal_in']!=='10.00'
  || $result['totals']['internal_out']!=='10.00'){
  throw new RuntimeException('Conferência por conta ou transferência incorrecta.');
 }
 if($result['status']!=='conferencia_informativa')
  throw new RuntimeException('Conferência confundida com fecho real.');
 echo "OK: abertura, dia, transferências, saldos finais e modo de leitura conferidos.\n";
}finally{$db->rollBack();}
if($db->table('Extracto')->count()!==0)throw new RuntimeException('Persistiu registo de teste.');
