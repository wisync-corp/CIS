<?php
// Parcelas.frm / Cobrança.frm regression tests, no database writes.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\ParcelasQuoteController;

function checkParcelas(bool $ok,string $label):void {
    if (!$ok) throw new RuntimeException("FALHOU: ".$label);
}
$class=new ReflectionClass(ParcelasQuoteController::class);
$cents=$class->getMethod('cents');
$rate=$class->getMethod('rateHundredths');
$percent=$class->getMethod('percent');
$amount=$class->getMethod('amount');
$periods=$class->getConstant('PERIODS');
checkParcelas($periods['1 Semana']===['periodo'=>15,'dias'=>7],'prazo semanal 1');
checkParcelas($periods['2 Semanas']===['periodo'=>15,'dias'=>15],'prazo semanal 2 (regra VBA)');
checkParcelas($periods['3 Semanas']===['periodo'=>30,'dias'=>21],'prazo semanal 3');
checkParcelas($periods['Mês']===['periodo'=>30,'dias'=>30],'prazo mensal');
checkParcelas($cents->invoke(null,'100.10','valor')===10010,'dois decimais exactos');
checkParcelas($rate->invoke(null,'25.00')===2500,'taxa em centésimos de %');
checkParcelas($percent->invoke(null,10010,2500)===2503,'juros HALF_UP');
checkParcelas($amount->invoke(null,2503)==='25.03','formato monetário');
checkParcelas($amount->invoke(null,10010+2503)==='125.13','valor da prestação');
checkParcelas($percent->invoke(null,200000,1000)===20000,'multa sobre juros, base 2000');
foreach (['-1','NaN','1,23','1.234','1e6'] as $bad) {
    try {
        $cents->invoke(null,$bad,'valor');
        throw new RuntimeException('Aceite valor inválido: '.$bad);
    } catch (\Illuminate\Validation\ValidationException $expected) {}
}
$route=collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
    ->first(fn($r)=>$r->uri()==='api/cis/contractos/{numero}/prestacoes/{ordem}/simular-parcelas');
checkParcelas((bool)$route,'rota Parcelas está registada');
checkParcelas(in_array('POST',$route->methods(),true),'rota usa POST');
checkParcelas(collect($route->gatherMiddleware())->contains('auth:sanctum'),'rota exige sessão');
echo "OK: Parcelas.frm (prazo, faixa, juros/multa, arredondamento), autorização e rota. Sem escritas.\n";
