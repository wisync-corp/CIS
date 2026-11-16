<?php
// Smoke test SEM movimentos reais, SEM ligação ao banco legado.
// Execução: após migrate em SQLite de CI, php tests/cash_smoke.php.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function checkCash(bool $ok,string $message):void {
    if (!$ok) throw new RuntimeException('Caixa: '.$message);
}
$controller=new \App\Http\Controllers\Api\CashController();
$method=new ReflectionMethod($controller,'cents');
$decimal=new ReflectionMethod($controller,'decimal');
foreach (['0'=>0,'0.00'=>0,'1'=>100,'1.2'=>120,'1.23'=>123,'123456.78'=>12345678] as $amount=>$expected) {
    checkCash($method->invoke($controller,(string)$amount)===$expected,'parse '.$amount);
    checkCash($method->invoke($controller,$decimal->invoke($controller,$expected))===$expected,
        'roundtrip '.$amount);
}
foreach (['-1','1.234','1,20','abc','01','1e4'] as $invalid) {
    try {
        $method->invoke($controller,$invalid);
        throw new RuntimeException('Valor invalido aceite: '.$invalid);
    } catch (\Illuminate\Validation\ValidationException $e) {
        // rejeição esperada
    }
}
checkCash(\Illuminate\Support\Facades\Schema::hasTable('cis_cash_accounts'),
    'tabela de contas em falta');
checkCash(\Illuminate\Support\Facades\Schema::hasTable('cis_cash_movements'),
    'tabela de movimentos em falta');
foreach (['opening_balance','cut_at','evidence_reference','status'] as $field) {
    checkCash(\Illuminate\Support\Facades\Schema::hasColumn('cis_cash_accounts',$field),
        'coluna '.$field.' em falta');
}
foreach (['account_id','reference','amount','transfer_group','contract_number'] as $field) {
    checkCash(\Illuminate\Support\Facades\Schema::hasColumn('cis_cash_movements',$field),
        'coluna '.$field.' em falta');
}
checkCash(\Illuminate\Support\Facades\DB::table('cis_cash_accounts')->count()===0,'CI deve iniciar com contas vazias');
checkCash(\Illuminate\Support\Facades\DB::table('cis_cash_movements')->count()===0,'CI deve iniciar sem movimentos');
echo "OK: Caixa Geral, schema e precisão monetária. Nenhum desembolso criado.\n";
