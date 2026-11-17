<?php
// Testes de prévias sem INSERT permanente: apenas SQLite efémero, com ROLLBACK.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CashController;

if(DB::getDriverName()!=='sqlite') throw new RuntimeException('BLOQUEADO: executar somente com SQLite de CI.');
function assertPreview(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('Prévia Caixa: '.$message);
}
$controller=new CashController();
$balance=new ReflectionMethod($controller,'balance');
$parse=new ReflectionMethod($controller,'cents');
$format=new ReflectionMethod($controller,'decimal');

$routes=collect(Route::getRoutes()->getRoutes());
foreach (['simular-movimento','simular-transferencia'] as $name) {
    $route=$routes->first(fn($r)=>$r->uri()==='api/cis/caixa/'.$name);
    assertPreview((bool)$route,'falta rota '.$name);
    assertPreview(in_array('POST',$route->methods(),true),'simulador deve usar POST');
    assertPreview(collect($route->gatherMiddleware())->contains('auth:sanctum'),
        'rota sem autenticacao '.$name);
}
$db=DB::connection();
$db->beginTransaction();
try{
    $a=$db->table('cis_cash_accounts')->insertGetId([
        'code'=>'PREV-A','name'=>'Simulação A','type'=>'numerario',
        'currency'=>'MZN','status'=>'activa','opening_balance'=>'100.00',
        'cut_at'=>'2026-11-17 09:00:00','opening_extracto_nr'=>0,
        'created_by'=>1,'validated_by'=>1,'validated_at'=>now(),
        'evidence_reference'=>'EXTRATO-REAL-NAO','created_at'=>now(),'updated_at'=>now(),
    ]);
    $b=$db->table('cis_cash_accounts')->insertGetId([
        'code'=>'PREV-B','name'=>'Simulação B','type'=>'banco',
        'currency'=>'MZN','status'=>'activa','opening_balance'=>'25.50',
        'cut_at'=>'2026-11-17 09:00:00','opening_extracto_nr'=>0,
        'created_by'=>1,'validated_by'=>1,'validated_at'=>now(),
        'evidence_reference'=>'EXTRATO-REAL-NAO','created_at'=>now(),'updated_at'=>now(),
    ]);
    $before=$db->table('Extracto')->count();
    $rows=$db->table('cis_cash_accounts')->get()->keyBy('id');
    assertPreview($balance->invoke($controller,$rows[$a])===10000,'saldo de origem');
    assertPreview($balance->invoke($controller,$rows[$b])===2550,'saldo de destino');
    $value=$parse->invoke($controller,'36.25');
    assertPreview($value===3625,'precisao exacta');
    assertPreview($format->invoke($controller,10000-$value)==='63.75','saida');
    assertPreview($format->invoke($controller,2550+$value)==='61.75','entrada da transferencia');
    assertPreview($db->table('Extracto')->count()===$before,'pre-calculo nao cria lancamentos');
    echo "OK: rotas autenticadas de simulação, saldos por conta, aritmética e nenhum lançamento financeiro.\n";
} finally{
    $db->rollBack();
}
