<?php
// Executar após composer install: php tests/contract_opening_pricing_smoke.php
// Não exige ligação MySQL nem modifica qualquer dado financeiro.
require __DIR__.'/../vendor/autoload.php';

use App\Services\LegacyContractPricing as Pricing;

function check(bool $condition, string $name): void {
    if (!$condition) throw new RuntimeException('FALHA: '.$name);
}
function near(float $actual,float $expected,string $name): void {
    check(abs($actual-$expected)<0.000001,$name.' (obtido '.$actual.')');
}
near(Pricing::round(2.345),2.34,'round para par 2.345');
near(Pricing::round(2.355),2.36,'round para par 2.355');

$fixo=Pricing::plan(3000,20,'Fixo','Mensal',3,'2026-11-14');
check(count($fixo['prestacoes'])===3,'3 parcelas do fixo');
near($fixo['prestacoes'][0]['amortizacao'],1000,'amortização fixa');
near($fixo['prestacoes'][0]['juros'],200,'juros fixos');
near($fixo['prestacoes'][0]['pagar'],1200,'valor a pagar fixo');
check($fixo['data_final']==='2027-02-14','data final fixa');
check($fixo['prestacoes'][2]['vencimento']==='2027-02-14','vencimento última parcela');

$unica=Pricing::plan(5000,25,'Fixo','Uma Parcela',3,'2026-11-14');
check(count($unica['prestacoes'])===1,'uma parcela em três meses');
check($unica['prestacoes'][0]['vencimento']==='2027-02-14','parcela única vence na data final');
near($unica['prestacoes'][0]['pagar'],6250,'parcela única');

$variavel=Pricing::plan(1000,1,'Variável','Uma Parcela',1,'2026-11-14');
near($variavel['prestacoes'][0]['juros'],0,'juro variável só na cobrança');
near($variavel['prestacoes'][0]['pagar'],1000,'principal variável');

$composto=Pricing::plan(1200,0,'Composto','Mensal',3,'2026-11-14');
check(count($composto['prestacoes'])===3,'composto três parcelas');
near($composto['prestacoes'][2]['pagar'],400,'composto 0%');

try {
    Pricing::plan(1000,20,'Fixo','Mensal',3,'2026-11-14');
    throw new RuntimeException('Era esperada a rejeição de centavos em coluna INT.');
} catch (\Illuminate\Validation\ValidationException $e) {
    // Comportamento seguro: não truncar capital em contratos de 3 parcelas.
}
echo "OK: motor Contractos.frm — 5 grupos de verificações sem gravação.\n";
