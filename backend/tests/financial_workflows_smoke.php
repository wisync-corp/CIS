<?php
// Testes sem gravação — prazos semanais, calendário e reconciliação.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\LegacyContractPricing as Pricing;
use App\Services\ContractCalculationStrategy as Strategy;

function assertFlow(bool $condition,string $description):void {
    if (!$condition)throw new RuntimeException('FALHA: '.$description);
}
$cases=[
    ['1 Semana',0,'Uma Parcela',1,'2026-11-23'],
    ['2 Semanas',0,'Semanal',2,'2026-11-30'],
    ['3 Semanas',0,'Semanal',3,'2026-12-07'],
    ['1 Mês',1,'Semanal',5,'2026-12-16'],
    ['2 Meses',2,'Semanal',9,'2027-01-16'],
    ['3 Meses',3,'Semanal',14,'2027-02-16'],
    ['2 Meses',2,'Mensal',2,'2027-01-16'],
    ['3 Meses',3,'Uma Parcela',1,'2027-02-16'],
];
foreach($cases as [$prazo,$months,$mode,$count,$last]) {
    $result=Pricing::plan(9000,25,'Fixo',$mode,$months,'2026-11-16',
        Strategy::FIXED_AMORTIZATION,$prazo,($prazo==='1 Semana'||$prazo==='2 Semanas')?15:30);
    $rows=$result['prestacoes'];
    assertFlow(count($rows)===$count,$prazo.' '.$mode.' quantidade');
    assertFlow($rows[count($rows)-1]['vencimento']===$last,$prazo.' data final');
    assertFlow(array_sum(array_column($rows,'amortizacao'))===9000,$prazo.' capital');
    assertFlow(array_sum(array_column($rows,'pagar'))===$result['total_pagar'],$prazo.' total');
    foreach ($rows as $row) {
        foreach(['capital','amortizacao','juros','pagar'] as $key)
            assertFlow(is_int($row[$key]),$prazo.' '.$key.' inteiro');
    }
}
// Três semanas não são válidas com período de referência de 15 dias.
try {
    Pricing::plan(9000,25,'Fixo','Semanal',0,'2026-11-16',
        Strategy::FIXED_AMORTIZATION,'3 Semanas',15);
    throw new RuntimeException('Três semanas com período de 15 dias deviam ser recusadas.');
} catch (\Illuminate\Validation\ValidationException $e) {}
$variable=Pricing::plan(1000,25,'Variável','Uma Parcela',0,'2026-11-16',
    Strategy::VARIABLE_COLLECTION,'2 Semanas',15);
assertFlow($variable['prestacoes'][0]['juros']===0,'Variável: juros apenas na cobrança');
assertFlow($variable['data_final']==='2026-11-30','Variável: data por semanas');
try {
 Pricing::plan(1000,25,'Composto','Semanal',2,'2026-11-16',
    Strategy::PRICE,'2 Meses',30);
 throw new RuntimeException('A conversão de juros compostos semanais devia estar bloqueada.');
} catch (\Illuminate\Validation\ValidationException $e) {}
// Prévia real do módulo Cobrança.frm, SEM abertura de transacções.
$controller=new \App\Http\Controllers\Api\ContractCollectionController();
$quoteMethod=new ReflectionMethod($controller,'quote');
$fixed=(object)[
 'Data de Cobrança'=>'2026-11-20','Data de Inicio'=>'2026-11-16',
 'Amortização'=>100,'Capital'=>100,'Valor de Juros'=>20,
 'Valor a Pagar'=>120,'% de Juro'=>20,'% de Multa'=>10,
 'Tipo de Juro'=>'Fixo','Nº da Parcela'=>1,
];
$late=$quoteMethod->invoke($controller,$fixed,'2026-11-25',true);
assertFlow($late['dias_atraso']===5,'atraso de cinco dias');
assertFlow($late['multa']===2&&$late['total']===122,'multa sobre juros e pagamento integral');
assertFlow($late['estado']==='Pago em Atraso','estado pago em atraso');
$variable=(object)[
 'Data de Cobrança'=>'2026-11-23','Data de Inicio'=>'2026-11-16',
 'Amortização'=>1000,'Capital'=>1000,'Valor de Juros'=>0,
 'Valor a Pagar'=>1000,'% de Juro'=>1,'% de Multa'=>10,
 'Tipo de Juro'=>'Variável','Nº da Parcela'=>1,
];
$quote=$quoteMethod->invoke($controller,$variable,'2026-11-20',false);
assertFlow($quote['juros']===40&&$quote['total']===1040,
    'juro variável: capital * taxa * quatro dias, arredondado para cima');
assertFlow($quote['dias_atraso']===0,'sem multa antes do vencimento');
echo "OK: calendários semanais, reconciliação, juro variável e cálculo de multa sem escrita.\n";
