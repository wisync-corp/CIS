<?php
// Sem acesso MySQL; valida as compatibilidades aprovadas para o CIS.
require __DIR__.'/../app/Services/ContractCalculationStrategy.php';

use App\Services\ContractCalculationStrategy as Strategy;

$expected=[
 'Fixo'=>[Strategy::FIXED_AMORTIZATION,Strategy::TOTAL,Strategy::SIMPLE],
 'Composto'=>[Strategy::DECLINING,Strategy::PRICE],
 'Variável'=>[Strategy::VARIABLE_COLLECTION],
];
foreach ($expected as $tipo=>$permitted) {
 if (Strategy::forType($tipo)!==$permitted) {
    throw new RuntimeException('Lista de modalidades incorrecta para '.$tipo);
 }
 foreach (Strategy::OPTIONS as $formula) {
    if (Strategy::compatible($tipo,$formula)!==in_array($formula,$permitted,true)) {
       throw new RuntimeException('Estratégia atribuída incorrectamente: '.$tipo.' / '.$formula);
    }
 }
}
echo "OK: seis estratégias compatíveis apenas com os respectivos tipos de juro.\n";
