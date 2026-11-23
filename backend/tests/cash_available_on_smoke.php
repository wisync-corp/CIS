<?php
// Saldo de uma conta numa data passada e montante que pode sair sem deixar dias seguintes negativos.
namespace Illuminate\Support\Facades { if (!class_exists(DB::class)) { class DB {} } }
namespace {
require __DIR__.'/../app/Services/CashAccountLedger.php';
if (!function_exists('abort_unless')) { function abort_unless($c,$code,$msg=''){ if(!$c) throw new RuntimeException($msg); } }
if (!function_exists('abort_if')) { function abort_if($c,$code,$msg=''){ if($c) throw new RuntimeException($msg); } }
final class FakeQ { public function __construct(private array $rows) {} public function where(...$a){ return $this; } public function get($c=null){ return collect_rows($this->rows); } }
function collect_rows(array $r){ return new class($r) implements IteratorAggregate { public function __construct(private array $r){} public function getIterator(): Iterator { return new ArrayIterator($this->r); } }; }
final class FakeDb { public function __construct(private array $rows) {} public function table($t){ return new FakeQ($this->rows); } }
$m=fn($d,$t,$v)=>(object)['Valor'=>$v,'Tipo de Movimento'=>$t,'d'=>$d];
$acc=(object)['id'=>1,'code'=>'BCI','status'=>'activa','currency'=>'MZN','opening_balance'=>'1000.00','cut_at'=>'2026-11-01 00:00:00','opening_extracto_nr'=>10,'validated_at'=>'2026-11-01'];
$db=new FakeDb([$m('2026-11-05','Entradas','5000.00'),$m('2026-11-10','Saídas','4500.00'),$m('2026-11-12','Entradas','2000.00')]);
$Cls='App\Services\CashAccountLedger';
$check=function($date,$at,$av) use($Cls,$db,$acc){ [$a,$b]=$Cls::availableOn($db,$acc,$date); if([$a,$b]!==[$at*100,$av*100]){ fwrite(STDERR,"FALHOU $date: ".json_encode([$a/100,$b/100])."\n"); exit(1);} };
$check('2026-11-03',1000,1000);   // antes da entrada: só o saldo inicial
$check('2026-11-06',6000,1500);   // 6000 nesse dia, mas a saída de dia 10 deixa só 1500 livres
$check('2026-11-11',1500,1500);
$check('2026-11-12',3500,3500);
echo "cash_available_on_smoke OK\n";
}
