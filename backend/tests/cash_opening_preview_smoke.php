<?php
// Explicitly SQLite and transaction-scoped: test maker/checker and historical
// account attribution without writing to any production DB.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
if(DB::getDriverName()!=='sqlite') throw new RuntimeException('BLOQUEADO: fixture de abertura permitida apenas em SQLite descartável.');

function assertOpening(bool $ok,string $msg):void { if(!$ok)throw new RuntimeException($msg); }
$db=DB::connection();
$db->beginTransaction();
try {
 $id=$db->table('cis_cash_accounts')->insertGetId([
  'code'=>'CI-CASH-OPENING','name'=>'CI teste de abertura',
  'type'=>'numerario','currency'=>'MZN','status'=>'em_preparacao',
  'created_by'=>100,'created_at'=>now(),'updated_at'=>now(),
 ]);
 $cash=new \App\Http\Controllers\Api\CashController();
 $method=new ReflectionMethod($cash,'accountRows');
 $maker=$method->invoke($cash,100)->first();
 $other=$method->invoke($cash,200)->first();
 assertOpening($maker['can_prepare']===true && $maker['can_validate']===false,'O autor pode preparar, não validar');
 assertOpening($other['can_prepare']===false,'Segundo responsável não deve preparar saldo de terceiro');
 $db->table('cis_cash_accounts')->where('id',$id)->update([
  'status'=>'pendente_validacao','opening_balance'=>'100.00',
  'cut_at'=>'2026-11-17 08:00:00','evidence_reference'=>'EXTRACTO-CI-001',
 ]);
 $maker=$method->invoke($cash,100)->first();
 $other=$method->invoke($cash,200)->first();
 assertOpening($maker['can_validate']===false,'Quem cadastrou não pode validar');
 assertOpening($other['can_validate']===true,'Segundo administrador pode validar proposta pendente');
 $db->table('cis_cash_accounts')->where('id',$id)->update([
  'status'=>'activa','opening_extracto_nr'=>0,'validated_by'=>200,'validated_at'=>now(),
 ]);
 $db->table('Extracto')->insert([
  'Tipo de Movimento'=>'Entradas','Valor'=>'999.00','Descrição'=>'Legado sem conta',
  'Data da Operação'=>'2026-11-17','account_id'=>null,
 ]);
 $db->table('Extracto')->insert([
  'Tipo de Movimento'=>'Entradas','Valor'=>'5.00','Descrição'=>'Entrada da conta',
  'Data da Operação'=>'2026-11-17','account_id'=>$id,
 ]);
 $third=$method->invoke($cash,100)->first();
 assertOpening($third['saldo_disponivel']==='105.00','Saldo conta=abertura+movimento atribuido; nunca absorver histórico sem conta');
 assertOpening($third['can_prepare']===false && $third['can_validate']===false,
  'Conta activa não deve permitir nova abertura nem validação');
 $routes=collect(Route::getRoutes()->getRoutes())->map(fn($r)=>[$r->uri(),$r->methods()]);
 foreach(['api/cis/caixa/contas/{id}/abertura','api/cis/caixa/contas/{id}/activar'] as $path){
  assertOpening($routes->contains(fn($r)=>$r[0]===$path&&in_array('POST',$r[1],true)),
   'Rota de abertura/validação ausente: '.$path);
 }
 echo "OK: dois responsáveis, estados e saldo por account_id (historico isolado), nenhum movimento persistido.\n";
} finally {
 $db->rollBack();
}
