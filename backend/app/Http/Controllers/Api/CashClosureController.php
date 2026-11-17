<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Fecho de CONTROLO diário por conta, imutável e não financeiro.
 * O Extracto continua o único livro de movimentos e a Tesouraria Premium
 * mantém reconciliação, liquidez e importação bancária.
 *
 * A operação de escrita só é permitida após homologação de tabela,
 * user MySQL específico e feature flag independente, DESACTIVADA por padrão.
 */
final class CashClosureController extends Controller
{
    private function access(Request $request,bool $write=false):void
    {
        $u=$request->user();
        abort_unless($u && $u->active && $u->role==='administrador',403);
        abort_unless(filter_var(env('CIS_PRIVATE_PREVIEW',false),FILTER_VALIDATE_BOOLEAN),503);
        abort_unless(Schema::connection('mysql_cash_closures')->hasTable('cis_cash_daily_closures')
            && Schema::hasTable('cis_cash_accounts')
            && Schema::hasColumn('Extracto','account_id'),503,
            'Esquema de fechos ainda não instalado.');
        if($write){
            abort_unless(filter_var(env('CIS_CASH_DAILY_CLOSE_APPROVED',false),FILTER_VALIDATE_BOOLEAN),503,
                'Fecho definitivo ainda não homologado.');
        }
    }

    private function cents(string $amount):int
    {
        abort_unless(preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D',$amount)===1,409,
            'Montante do Extracto não compatível com fecho em cêntimos.');
        [$n,$f]=array_pad(explode('.',$amount,2),2,'');
        return (int)$n*100+(int)str_pad($f,2,'0');
    }

    private function money(int $cents):string
    {
        return ($cents<0?'-':'').intdiv(abs($cents),100).'.'
            .str_pad((string)(abs($cents)%100),2,'0',STR_PAD_LEFT);
    }

    private function validDate(string $date):void
    {
        $today=CarbonImmutable::now('Africa/Maputo')->startOfDay();
        $day=CarbonImmutable::createFromFormat('!Y-m-d',$date,'Africa/Maputo');
        abort_unless($day && $day->lessThanOrEqualTo($today),422,
            'Não pode fechar uma data futura.');
    }

    private function calculate($db,object $account,string $date):array
    {
        abort_unless($account->status==='activa' && $account->currency==='MZN'
            && $account->opening_balance!==null
            && $account->opening_extracto_nr!==null
            && $account->validated_at!==null && $account->cut_at!==null,409,
            'A conta não está activa com abertura documentada.');
        abort_unless(substr((string)$account->cut_at,0,10)<=$date,409,
            'A data do fecho é anterior à abertura da conta.');
        $opening=$this->cents((string)$account->opening_balance);
        $before=0;$entries=0;$exits=0;$count=0;
        $max=(int)$account->opening_extracto_nr;
        // O histórico anterior ao marcador de abertura NUNCA entra no fecho.
        $movements=$db->table('Extracto')
            ->where('account_id',$account->id)
            ->where('Nr','>',$account->opening_extracto_nr)
            ->whereDate('Data da Operação','<=',$date)
            ->orderBy('Nr')->get(['Nr','Valor','Tipo de Movimento','Data da Operação']);
        foreach($movements as $row){
            $v=$this->cents((string)$row->Valor);
            $type=(string)$row->{'Tipo de Movimento'};
            abort_unless(in_array($type,['Entradas','Saídas'],true),409,
                'Natureza de movimento desconhecida. Fecho bloqueado.');
            $delta=$type==='Entradas'?$v:-$v;
            $max=max($max,(int)$row->Nr);
            if(substr((string)$row->{'Data da Operação'},0,10)<$date){
                $before+=$delta;
            }else{
                $count++;
                if($type==='Entradas')$entries+=$v;else $exits+=$v;
            }
        }
        $start=$opening+$before;
        $expected=$start+$entries-$exits;
        abort_unless($start>=0 && $expected>=0,409,'Saldo financeiro inconsistente.');
        return [
            'opening_amount'=>$this->money($start),
            'entries_amount'=>$this->money($entries),
            'exits_amount'=>$this->money($exits),
            'expected_balance'=>$this->money($expected),
            'movement_count'=>$count,
            'ledger_max_nr'=>$max,
        ];
    }

    private function writer()
    {
        $db=DB::connection('mysql_cash_closures');
        abort_unless($db->getDatabaseName()===DB::connection()->getDatabaseName(),503,
            'Ligação de fechos aponta para outra base.');
        return $db;
    }

    public function show(Request $request)
    {
        $this->access($request);
        $d=$request->validate([
            'date'=>['required','date_format:Y-m-d'],
            'account_id'=>['required','integer','min:1'],
        ]);
        $this->validDate($d['date']);
        $db=$this->writer();
        $account=$db->table('cis_cash_accounts')->where('id',$d['account_id'])->first();
        abort_unless($account,404,'Conta inexistente.');
        $existing=$db->table('cis_cash_daily_closures')
            ->where('account_id',$account->id)->where('closing_date',$d['date'])->first();
        // Um fecho confirmado é uma fotografia imutável. Não apresentar
        // valores recalculados de hoje como se fossem os da assinatura.
        $snapshot=$existing ? [
            'opening_amount'=>(string)$existing->opening_amount,
            'entries_amount'=>(string)$existing->entries_amount,
            'exits_amount'=>(string)$existing->exits_amount,
            'expected_balance'=>(string)$existing->expected_balance,
            'movement_count'=>(int)$existing->movement_count,
            'ledger_max_nr'=>(int)$existing->ledger_max_nr,
        ] : $this->calculate($db,$account,$d['date']);
        return response()->json([
            'date'=>$d['date'],'account_id'=>(int)$account->id,
            'code'=>$account->code,'name'=>$account->name,'account_type'=>$account->type,
            'snapshot'=>$snapshot,
            'existing'=>$existing,
            'can_confirm'=>!$existing
                && filter_var(env('CIS_CASH_DAILY_CLOSE_APPROVED',false),FILTER_VALIDATE_BOOLEAN),
            'notice'=>$existing
                ?'Fotografia do fecho original, não recalculada. Movimentos posteriores podem alterar o saldo corrente; a Tesouraria faz a conciliação externa.'
                :'Fecho de controlo por conta e dia. Não altera saldos, não bloqueia períodos e não substitui a reconciliação bancária da Tesouraria.',
        ])->header('Cache-Control','no-store, private');
    }

    public function store(Request $request)
    {
        $this->access($request,true);
        $d=$request->validate([
            'date'=>['required','date_format:Y-m-d'],
            'account_id'=>['required','integer','min:1'],
            'observed_balance'=>['required','string','regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
            'verification_method'=>['required','in:contagem_numerario,extracto_bancario,comprovativo_carteira'],
            'evidence_reference'=>['required','string','min:6','max:180'],
            'difference_reason'=>['nullable','string','max:255'],
            'verified_observed'=>['required','accepted'],
        ]);
        $this->validDate($d['date']);
        // Limite deliberado de fecho retrospectivo, para não institucionalizar
        // correcções de períodos históricos sem controlo específico.
        abort_unless(CarbonImmutable::createFromFormat('!Y-m-d',$d['date'],'Africa/Maputo')
            ->greaterThanOrEqualTo(CarbonImmutable::now('Africa/Maputo')->startOfDay()->subDays(31)),422,
            'Fechos anteriores a 31 dias exigem processo de regularização independente.');
        $db=$this->writer();
        try {
            return $db->transaction(function () use($db,$d,$request) {
                // A mesma lock order dos movimentos; nunca bloqueia todas as contas.
                $account=$db->table('cis_cash_accounts')
                    ->where('id',$d['account_id'])->lockForUpdate()->first();
                abort_unless($account,404,'Conta financeira não encontrada.');
                $methods=[
                    'numerario'=>'contagem_numerario',
                    'banco'=>'extracto_bancario',
                    'carteira_movel'=>'comprovativo_carteira',
                ];
                abort_unless(($methods[$account->type]??null)===$d['verification_method'],422,
                    'O método de conferência não corresponde ao tipo da conta.');
                abort_if($db->table('cis_cash_daily_closures')
                    ->where('account_id',$account->id)
                    ->where('closing_date',$d['date'])->exists(),409,
                    'O fecho dessa conta e data já foi registado e não pode ser duplicado.');
                $snapshot=$this->calculate($db,$account,$d['date']);
                $observed=$this->cents($d['observed_balance']);
                $difference=$observed-$this->cents($snapshot['expected_balance']);
                $reason=trim($d['difference_reason']??'');
                if($difference!==0 && mb_strlen($reason)<10){
                    throw ValidationException::withMessages([
                        'difference_reason'=>'Divergências exigem uma justificação de pelo menos 10 caracteres.',
                    ]);
                }
                $now=now('Africa/Maputo');
                $id=$db->table('cis_cash_daily_closures')->insertGetId([
                    'account_id'=>$account->id,'closing_date'=>$d['date'],
                    'currency'=>'MZN',
                    'opening_amount'=>$snapshot['opening_amount'],
                    'entries_amount'=>$snapshot['entries_amount'],
                    'exits_amount'=>$snapshot['exits_amount'],
                    'expected_balance'=>$snapshot['expected_balance'],
                    'observed_balance'=>$d['observed_balance'],
                    'difference_amount'=>$this->money($difference),
                    'movement_count'=>$snapshot['movement_count'],
                    'ledger_max_nr'=>$snapshot['ledger_max_nr'],
                    'verification_method'=>$d['verification_method'],
                    'evidence_reference'=>trim($d['evidence_reference']),
                    'difference_reason'=>$difference===0?null:$reason,
                    'closed_by'=>(int)$request->user()->id,
                    'closed_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
                ]);
                return response()->json([
                    'recorded'=>true,'closure_id'=>$id,
                    'account_id'=>(int)$account->id,'date'=>$d['date'],
                    'expected_balance'=>$snapshot['expected_balance'],
                    'observed_balance'=>$d['observed_balance'],
                    'difference_amount'=>$this->money($difference),
                    'notice'=>'Fecho de controlo registado. Nenhum movimento ou saldo foi alterado; o período não foi bloqueado.',
                ],201)->header('Cache-Control','no-store, private');
            },3);
        } catch(\Illuminate\Database\UniqueConstraintViolationException $e){
            abort(409,'Fecho já registado por outra operação simultânea. Consulte o histórico.');
        }
    }
}
