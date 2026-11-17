<?php
// Smoke de escrita atómica da Caixa APENAS SQLite CI efémera e ROLLBACK.
// Nenhuma operação chega ao MySQL operacional.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\CashPostingController;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

if (DB::getDriverName()!=='sqlite') throw new RuntimeException('BLOQUEADO: teste apenas SQLite de CI.');
if(!Schema::hasColumn('Extracto','operation_key'))throw new RuntimeException('operation_key em falta.');
$connection=config('database.connections.sqlite');
config(['database.connections.mysql_cash_ledger'=>$connection]);
DB::purge('mysql_cash_ledger');
foreach(['CIS_PRIVATE_PREVIEW','CIS_CASH_WRITES_APPROVED','CIS_CASH_LEDGER_POSTING_APPROVED'] as $flag){
    $_ENV[$flag]='true';
    $_SERVER[$flag]='true';
    putenv($flag.'=true');
    if(!filter_var(env($flag,false),FILTER_VALIDATE_BOOLEAN))throw new RuntimeException($flag.' não injectada para teste.');
}
function testCash(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('CAIXA REAL: '.$message);
}
function callCash(CashPostingController $controller,string $method,array $params,int $actor=101){
    $r=Request::create('/api/cis/caixa/'.$method,'POST',$params);
    $user=new User();
    $user->id=$actor;
    $user->active=true;
    $user->role='administrador';
    $r->setUserResolver(static fn()=>$user);
    return $controller->$method($r)->getData(true);
}
function refused(callable $fn,int $code,string $context):void{
    try {$fn(); throw new RuntimeException('Sem recusa: '.$context);}
    catch(HttpException $e){testCash($e->getStatusCode()===$code,'código HTTP inesperado '.$context.': '.$e->getStatusCode());}
}
$db=DB::connection();
$db->beginTransaction();
try{
    $date=now('Africa/Maputo')->format('Y-m-d H:i:s');
    $source=$db->table('cis_cash_accounts')->insertGetId([
        'code'=>'CI-FUND-A','name'=>'Conta de testes A','type'=>'numerario',
        'currency'=>'MZN','status'=>'activa','opening_balance'=>'100.00',
        'cut_at'=>$date,'opening_extracto_nr'=>0,'created_by'=>101,
        'validated_by'=>101,'validated_at'=>$date,'evidence_reference'=>'CI-NOT-REAL',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $destination=$db->table('cis_cash_accounts')->insertGetId([
        'code'=>'CI-FUND-B','name'=>'Conta de testes B','type'=>'banco',
        'currency'=>'MZN','status'=>'activa','opening_balance'=>'25.00',
        'cut_at'=>$date,'opening_extracto_nr'=>0,'created_by'=>101,
        'validated_by'=>101,'validated_at'=>$date,'evidence_reference'=>'CI-NOT-REAL',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $controller=new CashPostingController();
    $entry=[
        'account_id'=>$source,'type'=>'saida','amount'=>'60.00',
        'category'=>'Despesas','description'=>'Manutenção operacional',
        'evidence_reference'=>'COMPROV-001',
        'idempotency_key'=>'00000000-0000-4000-8000-000000000001',
    ];
    $posted=callCash($controller,'record',$entry);
    testCash($posted['registered']===true && $posted['replayed']===false
        && $posted['saldo_actual']==='40.00','primeira saída 60 de 100');
    $count=$db->table('Extracto')->count();
    testCash($count===1,'uma única saída');
    $replayed=callCash($controller,'record',$entry);
    testCash($replayed['replayed']===true && $db->table('Extracto')->count()===$count,
        'retry idempotente sem duplicação');
    refused(fn()=>callCash($controller,'record',array_replace($entry,['amount'=>'61.00'])),409,
        'chave igual com montante diferente');
    refused(fn()=>callCash($controller,'record',array_replace($entry,[
        'amount'=>'50.00','idempotency_key'=>'00000000-0000-4000-8000-000000000002',
    ])),409,'saldo insuficiente');

    $transfer=[
        'source_account_id'=>$source,'destination_account_id'=>$destination,
        'amount'=>'15.00','description'=>'Transferência entre contas',
        'evidence_reference'=>'COMPROV-TRANSFER-1',
        'confirmed_source_debit'=>true,'confirmed_destination_credit'=>true,
        'idempotency_key'=>'00000000-0000-4000-8000-000000000003',
    ];

    // Introduzir falha na inserção da segunda perna; a primeira deve ser revertida.
    $db->statement("CREATE TRIGGER ci_fail_transfer_in BEFORE INSERT ON Extracto WHEN NEW.operation_key LIKE '%:2' BEGIN SELECT RAISE(ABORT,'CI failure'); END");
    try {
        callCash($controller,'transfer',$transfer);
        throw new RuntimeException('Transferência não falhou no trigger simulado.');
    } catch(\Illuminate\Database\QueryException $expected){}
    $db->statement('DROP TRIGGER ci_fail_transfer_in');
    testCash($db->table('Extracto')->count()===1,
        'falha na segunda perna deve reverter a primeira');

    $posted=callCash($controller,'transfer',$transfer);
    testCash($posted['registered']===true && $posted['replayed']===false
        && $posted['source_balance']==='25.00'
        && $posted['destination_balance']==='40.00','duas pernas correctas');
    testCash($db->table('Extracto')->count()===3,'saída original + transferência 2 pernas');
    $twice=callCash($controller,'transfer',$transfer);
    testCash($twice['replayed']===true && $db->table('Extracto')->count()===3,
        'replay de transferência não duplica');
    refused(fn()=>callCash($controller,'transfer',array_replace($transfer,[
        'amount'=>'14.00',
    ])),409,'replay com payload diferente');
    refused(fn()=>callCash($controller,'transfer',array_replace($transfer,[
        'destination_account_id'=>$source,
        'idempotency_key'=>'00000000-0000-4000-8000-000000000004',
    ])),422,'mesma conta');
    testCash($db->table('Extracto')->whereNotNull('operation_key')->count()===3,
        'todas as pernas têm chaves persistidas');
    echo "OK: saída real, saldo mínimo, retry idempotente, transferência atómica, rollback da segunda perna e rejeição de payload divergente, só em SQLite.\n";
}finally{
    $db->rollBack();
}
testCash($db->table('Extracto')->count()===0,'nenhuma transacção escapou do rollback CI');
