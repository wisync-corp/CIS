<?php
// Ensaio do novo campo de idempotência em SQLite descartável de CI.
// Não reutilizar contra MySQL de produção.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (DB::getDriverName()!=='sqlite') throw new RuntimeException('Teste permitido apenas em SQLite efémero.');
function checkOperationKey(bool $ok,string $message):void {
    if (!$ok)throw new RuntimeException('Idempotência Caixa: '.$message);
}
checkOperationKey(Schema::hasColumn('Extracto','operation_key'),'coluna ausente');
checkOperationKey(Schema::hasColumn('Extracto','account_id'),'coluna account_id existente preservada');
checkOperationKey(!Schema::hasTable('cis_cash_movements'),'livro financeiro duplicado');
$rowsBefore=DB::table('Extracto')->count();
DB::beginTransaction();
try {
    $values=['Tipo de Movimento'=>'Entradas','Valor'=>'10.00',
        'Descrição'=>'Ensaios sem impacto','Data da Operação'=>'2026-11-17'];
    DB::table('Extracto')->insert($values+['operation_key'=>null]);
    DB::table('Extracto')->insert($values+['operation_key'=>null]);
    DB::table('Extracto')->insert($values+['operation_key'=>'ci:cash:op-A']);
    $duplicate=false;
    try{
        DB::table('Extracto')->insert($values+['operation_key'=>'ci:cash:op-A']);
    }catch(\Illuminate\Database\UniqueConstraintViolationException $e){
        $duplicate=true;
    }
    checkOperationKey($duplicate,'mesma operation_key foi aceite duas vezes');
    DB::table('Extracto')->insert($values+['operation_key'=>'ci:cash:op-A:destination']);
    checkOperationKey(DB::table('Extracto')->whereNull('operation_key')->count()===2,
        'chave NULL deve permitir registos legados');
    checkOperationKey(DB::table('Extracto')->whereNotNull('operation_key')->count()===2,
        'chaves distintas de movimento preservadas');
}finally{
    DB::rollBack();
}
checkOperationKey(DB::table('Extracto')->count()===$rowsBefore,
    'testes devem deixar o Extracto original intacto');
echo "OK: operation_key UNIQUE nullable; NULL histórico preservado; duplicados bloqueados.\n";
