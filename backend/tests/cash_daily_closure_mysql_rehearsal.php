<?php
// Escrever/ensaiar fecho APENAS no clone Docker isolado, sempre ROLLBACK.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\CashClosureController;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
if(DB::getDriverName()!=='mysql'
 || config('database.connections.mysql.host')!=='cash-close-mysql'
 || !filter_var(env('CIS_ISOLATED_REHEARSAL',false),FILTER_VALIDATE_BOOLEAN)){
 throw new RuntimeException('BLOQUEADO: ensaio permitido apenas na cópia MySQL cash-close-mysql.');
}
foreach(['CIS_PRIVATE_PREVIEW','CIS_CASH_DAILY_CLOSE_APPROVED'] as $f){
 $_ENV[$f]='true';$_SERVER[$f]='true';putenv($f.'=true');
}
$db=DB::connection('mysql_cash_closures');
if($db->getDatabaseName()!=='cis')throw new RuntimeException('Base de dados inesperada.');
$before=$db->table('cis_cash_daily_closures')->count();
$ledgerBefore=$db->table('Extracto')->count();
$accountsBefore=$db->table('cis_cash_accounts')->count();
$realUser=User::query()->where('role','administrador')->where('active',true)->first();
if(!$realUser)throw new RuntimeException('Restauro não inclui administrador activo.');
$ctl=new CashClosureController();
$day=now('Africa/Maputo')->format('Y-m-d');
function callClose(CashClosureController $ctl,string $method,array $data,$user):array{
 $request=Request::create('/api/cis/caixa/fecho-diario',$method==='show'?'GET':'POST',$data);
 $request->setUserResolver(static fn()=>$user);
 return $ctl->$method($request)->getData(true);
}
$db->beginTransaction();
try{
 $nr=(int)($db->table('Extracto')->max('Nr')??0);
 $name='CI-FECHO-'.bin2hex(random_bytes(4));
 $a=$db->table('cis_cash_accounts')->insertGetId([
  'code'=>$name,'name'=>'Ensaio isolado','type'=>'numerario','currency'=>'MZN',
  'status'=>'activa','opening_balance'=>'100.00','opening_extracto_nr'=>$nr,
  'cut_at'=>$day.' 07:00:00','validated_at'=>now(),
  'created_by'=>$realUser->id,'validated_by'=>$realUser->id,
  'evidence_reference'=>'EXTRACTO-NAO-REAL','created_at'=>now(),'updated_at'=>now(),
 ]);
 $base=['date'=>$day,'account_id'=>$a];
 $p=callClose($ctl,'show',$base,$realUser);
 if($p['snapshot']['expected_balance']!=='100.00'||!$p['can_confirm'])
  throw new RuntimeException('Prévia de fecho inesperada.');
 $saved=callClose($ctl,'store',$base+[
  'observed_balance'=>'99.00',
  'verification_method'=>'contagem_numerario',
  'evidence_reference'=>'CONTAGEM-ENS-001',
  'difference_reason'=>'Diferença controlada apenas no ensaio',
  'verified_observed'=>true,
 ],$realUser);
 if(!$saved['recorded']||$saved['difference_amount']!=='-1.00')
  throw new RuntimeException('Fecho não gravado com divergência.');
 $already=callClose($ctl,'show',$base,$realUser);
 if($already['can_confirm']||$already['existing']===null)
  throw new RuntimeException('Fecho anterior não foi encontrado.');
 echo "OK: consulta, registo imutável e diferença documental no MySQL restaurado.\n";
}finally{
 $db->rollBack();
}
if($db->table('cis_cash_daily_closures')->count()!==$before
 ||$db->table('Extracto')->count()!==$ledgerBefore
 ||$db->table('cis_cash_accounts')->count()!==$accountsBefore){
 throw new RuntimeException('Ensaio deixou dados temporários fora do ROLLBACK.');
}
echo "ENSAIO FUNCIONAL DE FECHO APROVADO: todas as inserções revertidas.\n";
