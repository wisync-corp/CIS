<?php
// Generic simulator: fixed-money arithmetic, period rules, and route access; no writes.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Http\Controllers\Api\GenericSimulatorController;
use Illuminate\Validation\ValidationException;

function verifySim(bool $ok,string $message):void {
    if(!$ok) throw new RuntimeException('Falhou: '.$message);
}
$ref=new ReflectionClass(GenericSimulatorController::class);
$terms=$ref->getConstant('TERMS');
verifySim($terms['1 Semana']===['periodo'=>15,'dias'=>7],'1 semana');
verifySim($terms['2 Semanas']===['periodo'=>15,'dias'=>15],'2 semanas VBA');
verifySim($terms['3 Semanas']===['periodo'=>30,'dias'=>21],'3 semanas');
verifySim($terms['Mês']===['periodo'=>30,'dias'=>30],'mês');
$parse=$ref->getMethod('decimal');
$amount=$ref->getMethod('amount');
verifySim($parse->invoke(null,'1.01','montante')===101,'valor com dois decimais');
verifySim($parse->invoke(null,'20.00','taxa')===2000,'taxa fixa 20%');
verifySim($amount->invoke(null,2525)==='25.25','formato de saída');
foreach(['-1','1,50','01','1.234','1e5','NaN'] as $bad){
    try {$parse->invoke(null,$bad,'montante');throw new RuntimeException('aceitou montante inválido');}
    catch(ValidationException $ok){}
}
$route=collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
    ->first(fn($r)=>$r->uri()==='api/cis/simulador/calcular');
verifySim((bool)$route,'rota independente do contrato');
verifySim(in_array('POST',$route->methods(),true),'POST apenas');
verifySim(collect($route->gatherMiddleware())->contains('auth:sanctum'),'sessão exigida');
echo "OK: simulador genérico independente, prazos, valores exactos e acesso autenticado, sem escrita.\n";
