<?php
// Read-only tests for payment scenario endpoints. No test loans, INSERT or payment.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\CollectionScenarioPreviewController;
use Illuminate\Validation\ValidationException;

function checkCollection(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('FALHOU: '.$label);
}
$class=new ReflectionClass(CollectionScenarioPreviewController::class);
$cents=$class->getMethod('cents');
$fmt=$class->getMethod('fmt');
foreach ([
 '0'=>0,'0.01'=>1,'1'=>100,'100.50'=>10050,
 '25000.99'=>2500099,'16777216.00'=>1677721600,
] as $input=>$value) {
    checkCollection($cents->invoke(null,(string)$input,'valor_pago')===$value,'cents '.$input);
    checkCollection($fmt->invoke(null,$value)===(string)(intdiv($value,100)).'.'.str_pad((string)($value%100),2,'0',STR_PAD_LEFT),'fmt '.$input);
}
foreach (['-1','1.234','1e3','NaN','1,50','',' 1 0'] as $invalid) {
    try {
        $cents->invoke(null,$invalid,'valor_pago');
        throw new RuntimeException('FALHOU: valor inválido aceite: '.$invalid);
    } catch (ValidationException $expected) {
        // Reject invalid monetary inputs, never coerce to arbitrary values.
    }
}
$routes=collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->map(fn($r)=>$r->uri())->all();
checkCollection(in_array('api/cis/contractos/{numero}/prestacoes/{ordem}/simular-cenario',$routes,true),
    'endpoint read-only de cenários não registado');
echo "OK: validação de montantes exactos e rota de cenários de cobrança; sem escritas financeiras.\n";
