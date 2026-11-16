<?php
// Sem gravações ou acesso ao MySQL. Executar com o Laravel instalado:
// php tests/contract_opening_pricing_smoke.php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LegacyContractPricing as Pricing;
use App\Services\ContractCalculationStrategy as Strategy;

function check(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException('FALHA: '.$message);
}
check(Pricing::upwards(100.01)===101,'arredonda 100.01 para 101');
check(Pricing::upwards(100)===100,'montante inteiro permanece 100');
check(Pricing::upwards(0.0001)===1,'juros positivos sobem sempre');
$cases=[
    ['Fixo',Strategy::FIXED_AMORTIZATION,3000,20,3,3600,600,[1200,1200,1200]],
    ['Fixo',Strategy::TOTAL,1000,25,3,1250,250,[417,417,416]],
    ['Fixo',Strategy::SIMPLE,1000,20,3,1600,600,[534,534,532]],
    ['Composto',Strategy::DECLINING,1000,10,3,1201,201,[434,401,366]],
    ['Composto',Strategy::PRICE,1000,10,3,1207,207,[403,403,401]],
    ['Composto',Strategy::PRICE,1200,0,3,1200,0,[400,400,400]],
    ['Variável',Strategy::VARIABLE_COLLECTION,1000,20,1,1000,0,[1000]],
];
foreach ($cases as [$tipo,$strategy,$amount,$taxa,$months,$due,$interest,$expectedPayments]) {
    $modo=$tipo==='Variável'?'Uma Parcela':'Mensal';
    $result=Pricing::plan($amount,$taxa,$tipo,$modo,$months,'2026-11-16',$strategy);
    $lines=$result['prestacoes'];
    check(array_sum(array_column($lines,'amortizacao'))===$amount,$strategy.': capital totalmente liquidado');
    check($result['total_juros']===$interest,$strategy.': juros esperados');
    check($result['total_pagar']===$due,$strategy.': total previsto');
    check(array_column($lines,'pagar')===$expectedPayments,$strategy.': distribuição');
    foreach ($lines as $line) {
        foreach (['capital','amortizacao','juros','pagar'] as $field) {
            check(is_int($line[$field]),$strategy.': '.$field.' não inteiro');
        }
        check($line['pagar']===$line['amortizacao']+$line['juros'],$strategy.': reconciliação da prestação');
    }
}
try {
    Pricing::plan(1000.5,20,'Fixo','Mensal',2,'2026-11-16',Strategy::FIXED_AMORTIZATION);
    throw new RuntimeException('Era obrigatório recusar principal com centavos.');
} catch (\Illuminate\Validation\ValidationException $e) {}
echo "OK: sete estratégias/casos, arredondamento para cima e reconciliação de MT inteiros.\n";
