<?php
// Numeração da nova ficha: segue a última, mantendo prefixo e zeros.
require __DIR__.'/../app/Http/Controllers/Controller.php';
require __DIR__.'/../app/Http/Controllers/Api/ClientCreateController.php';
use App\Http\Controllers\Api\ClientCreateController as C;
$cases = ['C-0099' => 'C-0100', '0007' => '0008', '41' => '42', 'MUT/2026/009' => 'MUT/2026/010', '' => '1', 'ABC' => 'ABC-1', '12A' => '13A'];
foreach ($cases as $in => $want) {
    $got = C::increment($in);
    if ($got !== $want) { fwrite(STDERR, "FALHOU: $in → $got (esperado $want)\n"); exit(1); }
}
echo "client_create_smoke OK\n";
