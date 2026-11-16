<?php
// Smoke test de domínio; sem acesso à base de dados ou gravação.
require __DIR__.'/../app/Services/ContractCalculationStrategy.php';

use App\Services\ContractCalculationStrategy as Strategy;

$expected=[
    'Fixo' => 'Amortização fixa com juro fixo',
    'Composto' => 'Tabela Price',
    'Variável' => 'Juro variável na cobrança',
];
foreach ($expected as $tipo=>$estrategia) {
    if (Strategy::supportedFor($tipo)!==$estrategia || !Strategy::compatible($tipo,$estrategia)) {
        throw new RuntimeException('Estratégia VBA não coincide com o tipo '.$tipo);
    }
    foreach (Strategy::OPTIONS as $option) {
        if (Strategy::compatible($tipo,$option)!==($option===$estrategia)) {
            throw new RuntimeException('Estratégia não homologada erroneamente activada: '.$option);
        }
    }
}
echo "OK: opções de cálculo e compatibilidade com os três tipos de juro.\n";
