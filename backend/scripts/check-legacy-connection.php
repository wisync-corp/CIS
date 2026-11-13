<?php
// Teste somente de leitura: verifica as quatro tabelas sem revelar registos.
declare(strict_types=1);
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = \Illuminate\Support\Facades\DB::connection('mysql_legacy');
foreach (['Clientes', 'Contractos', 'Extracto', 'Usuarios'] as $table) {
    $total = $connection->table($table)->count();
    echo $table.': '.$total.PHP_EOL;
}
echo "Ligação Laravel -> MySQL confirmada. Nenhum registo alterado.".PHP_EOL;
