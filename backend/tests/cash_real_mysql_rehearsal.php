<?php
// Teste com ESQUEMA LEGADO REAL restaurado em MySQL DESCARTÁVEL.
// APENAS quando o host é o container efémero cash-real-mysql.
// Todas as linhas de ensaio são revertidas no fim da transacção.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\Api\CashPostingController;
use App\Models\User;
use Illuminate\Http\Request;

$host=(string)config('database.connections.mysql.host');
if (DB::getDriverName()!=='mysql' || $host!=='cash-real-mysql'
    || !filter_var(env('CIS_ISOLATED_REHEARSAL',false),FILTER_VALIDATE_BOOLEAN)) {
    throw new RuntimeException('BLOQUEADO: ensaio permitido apenas em cash-real-mysql isolado.');
}
foreach(['CIS_PRIVATE_PREVIEW','CIS_CASH_WRITES_APPROVED','CIS_CASH_LEDGER_POSTING_APPROVED'] as $f){
    $_ENV[$f]='true';$_SERVER[$f]='true';putenv($f.'=true');
}
if (!Schema::hasTable('cis_cash_accounts')
    || !Schema::hasColumn('Extracto','operation_key')) {
    throw new RuntimeException('Coluna de operação ou contas ausentes no restauro.');
}
$user=User::query()->where('role','administrador')->where('active',true)->first();
if (!$user)throw new RuntimeException('Restauro não tem administrador real para o teste.');
$db=DB::connection('mysql_cash_ledger');
if ($db->getDatabaseName()!=='cis')throw new RuntimeException('Base inesperada.');
$controller=new CashPostingController();

function assertReal(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('Ensaio MySQL: '.$message);
}
function simulatePosting(CashPostingController $controller,string $method,array $payload,User $user):array{
    $request=Request::create('/api/cis/caixa/'.$method,'POST',$payload);
    $request->setUserResolver(static fn()=>$user);
    return $controller->$method($request)->getData(true);
}
$before=$db->table('Extracto')->count();
$beforeSum=$db->table('Extracto')->sum('Valor');
$beforeAccounts=$db->table('cis_cash_accounts')->count();
$db->beginTransaction();
try{
    $now=now('Africa/Maputo')->format('Y-m-d H:i:s');
    $code='CI-REHEARSAL-'.substr(bin2hex(random_bytes(4)),0,8);
    $id=$db->table('cis_cash_accounts')->insertGetId([
        'code'=>$code,'name'=>'Teste isolado de Caixa',
        'type'=>'numerario','currency'=>'MZN','status'=>'activa',
        'opening_balance'=>'100.00','cut_at'=>$now,
        'opening_extracto_nr'=>(int)($db->table('Extracto')->max('Nr')??0),
        'evidence_reference'=>'REHEARSAL-NOT-REAL',
        'created_by'=>$user->id,'validated_by'=>$user->id,'validated_at'=>$now,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $destinationId=$db->table('cis_cash_accounts')->insertGetId([
        'code'=>$code.'-B','name'=>'Segunda conta de ensaio',
        'type'=>'banco','currency'=>'MZN','status'=>'activa',
        'opening_balance'=>'25.00','cut_at'=>$now,
        'opening_extracto_nr'=>(int)($db->table('Extracto')->max('Nr')??0),
        'evidence_reference'=>'REHEARSAL-NOT-REAL',
        'created_by'=>$user->id,'validated_by'=>$user->id,'validated_at'=>$now,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $record=[
        'account_id'=>$id,'type'=>'saida','amount'=>'30.00','category'=>'Operacoes',
        'description'=>'Despesa de ensaio controlada',
        'evidence_reference'=>'REHEARSAL-TESTE-001',
        'idempotency_key'=>'00000000-0000-4000-8000-000000000101',
    ];
    $first=simulatePosting($controller,'record',$record,$user);
    assertReal($first['registered']===true&&!$first['replayed']
        &&$first['saldo_actual']==='70.00','escrita real do Extracto no esquema restaurado');
    $second=simulatePosting($controller,'record',$record,$user);
    assertReal($second['replayed']===true,'retry deve ser idempotente');
    assertReal($db->table('Extracto')->where('account_id',$id)->count()===1,
        'não pode haver duplicação');
    assertReal((int)$db->table('Extracto')->where('account_id',$id)->first()->Nr===(int)$first['extracto_nr'],
        'linha idempotente mantém o mesmo Nr');
    $transfer=[
        'source_account_id'=>$id,'destination_account_id'=>$destinationId,
        'amount'=>'20.00','description'=>'Transferencia em ensaio real',
        'evidence_reference'=>'REHEARSAL-TRANSFER-001',
        'confirmed_source_debit'=>true,'confirmed_destination_credit'=>true,
        'idempotency_key'=>'00000000-0000-4000-8000-000000000102',
    ];
    $postedTransfer=simulatePosting($controller,'transfer',$transfer,$user);
    assertReal($postedTransfer['registered']===true&&!$postedTransfer['replayed']
        &&$postedTransfer['source_balance']==='50.00'
        &&$postedTransfer['destination_balance']==='45.00',
        'transferência atómica no esquema legado');
    assertReal($db->table('Extracto')->whereIn('account_id',[$id,$destinationId])->count()===3,
        'uma saída e duas pernas de transferência');
    $replayTransfer=simulatePosting($controller,'transfer',$transfer,$user);
    assertReal($replayTransfer['replayed']===true
        &&$db->table('Extracto')->whereIn('account_id',[$id,$destinationId])->count()===3,
        'replay de transferência mantém exactamente duas pernas');
    echo "OK: inserção no Extracto legado, replay idempotente e duas pernas atómicas em MySQL isolado.\n";
} finally {
    $db->rollBack();
}
assertReal($db->table('Extracto')->count()===$before,'registos Extracto revertidos');
assertReal((string)$db->table('Extracto')->sum('Valor')===(string)$beforeSum,'montante total preservado');
assertReal($db->table('cis_cash_accounts')->count()===$beforeAccounts,'contas de ensaio revertidas');
echo "OK: todas as operações de ensaio revertidas no MySQL ISOLADO.\n";
