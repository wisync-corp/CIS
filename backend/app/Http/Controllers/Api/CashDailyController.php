<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conferência diária somente leitura; NÃO é um fecho formal nem reconciliação.
 * Fonte única: Extracto após opening_extracto_nr de cada conta.
 */
final class CashDailyController extends Controller
{
    private function cents(string $amount): int
    {
        abort_unless(preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D',$amount)===1,
            409,'Montante inválido no Extracto.');
        [$n,$f]=array_pad(explode('.',$amount,2),2,'');
        return (int)$n*100+(int)str_pad($f,2,'0');
    }
    private function money(int $cents): string
    {
        return ($cents<0?'-':'').intdiv(abs($cents),100).'.'.str_pad((string)(abs($cents)%100),2,'0',STR_PAD_LEFT);
    }

    public function index(Request $request)
    {
        $u=$request->user();
        \App\Support\Permissions::require($u,'caixa');
        abort_unless(filter_var(env('CIS_PRIVATE_PREVIEW',false),FILTER_VALIDATE_BOOLEAN),503);
        abort_unless(Schema::hasTable('cis_cash_accounts')
            && Schema::hasColumn('Extracto','account_id')
            && Schema::hasColumn('Extracto','operation_key'),503);
        $d=$request->validate([
            'date'=>['required','date_format:Y-m-d'],
            'account_id'=>['nullable','integer','min:1'],
        ]);
        $date=CarbonImmutable::createFromFormat('!Y-m-d',$d['date'],'Africa/Maputo');
        abort_unless($date && $date->lessThanOrEqualTo(CarbonImmutable::now('Africa/Maputo')->endOfDay()),
            422,'A data não pode estar no futuro.');

        $accounts=DB::table('cis_cash_accounts')
            ->when(isset($d['account_id']),fn($q)=>$q->where('id',$d['account_id']))
            ->orderBy('code')->get(['id','code','name','status','opening_balance','opening_extracto_nr','cut_at','validated_at'])
            ->filter(fn($a)=>\App\Support\Branches::accountVisible($u,(int)$a->id))->values();
        abort_if(isset($d['account_id']) && $accounts->isEmpty(),404,'Conta não encontrada.');

        $result=[];$totals=['opening'=>0,'entries'=>0,'exits'=>0,'closing'=>0,'internal_in'=>0,'internal_out'=>0];
        $excluded=0;$warnings=[];
        foreach($accounts as $a){
            if($a->status!=='activa'){
                $excluded++;
                continue;
            }
            abort_unless($a->opening_balance!==null && $a->opening_extracto_nr!==null
                && $a->cut_at!==null && $a->validated_at!==null,409,
                'Conta activa sem abertura completa.');
            $cutDate=substr((string)$a->cut_at,0,10);
            if($cutDate>$d['date']){ $excluded++;continue; }
            $opening=$this->cents((string)$a->opening_balance);
            $in=0;$out=0;$internalIn=0;$internalOut=0;
            $before=0;$n=0;
            $movements=DB::table('Extracto')
                ->where('account_id',$a->id)->where('Nr','>',$a->opening_extracto_nr)
                ->whereDate('Data da Operação','<=',$d['date'])
                ->orderBy('Nr')
                ->get(['Nr','Valor','Tipo de Movimento','Data da Operação','Descrição','operation_key']);
            foreach($movements as $m){
                $amount=$this->cents((string)$m->Valor);
                $type=(string)$m->{'Tipo de Movimento'};
                abort_unless(in_array($type,['Entradas','Saídas'],true),409,
                    'Natureza financeira desconhecida no Extracto.');
                $delta=$type==='Entradas'?$amount:-$amount;
                if(substr((string)$m->{'Data da Operação'},0,10)<$d['date']){
                    $before+=$delta;
                    continue;
                }
                $n++;
                if($type==='Entradas')$in+=$amount;else $out+=$amount;
                $isInternal=$m->operation_key!==null
                    && ((preg_match('/^cash:[0-9a-f-]{36}:[12]$/i',(string)$m->operation_key)
                         && str_starts_with((string)$m->{'Descrição'},'[TRANSFER '))
                        || (preg_match('/^cash:reverse:transfer:[0-9]+:[12]$/',(string)$m->operation_key)
                         && str_starts_with((string)$m->{'Descrição'},'[ESTORNO TRANSFER ')));
                if($isInternal){
                    if($type==='Entradas')$internalIn+=$amount;else $internalOut+=$amount;
                }
            }
            $start=$opening+$before;
            $end=$start+$in-$out;
            abort_unless($start>=0 && $end>=0,409,'Saldo negativo detectado; conferência suspensa.');
            $row=[
                'account_id'=>$a->id,'code'=>$a->code,'name'=>$a->name,
                'opening'=>$this->money($start),
                'entries'=>$this->money($in),'exits'=>$this->money($out),
                'internal_in'=>$this->money($internalIn),'internal_out'=>$this->money($internalOut),
                'external_in'=>$this->money($in-$internalIn),
                'external_out'=>$this->money($out-$internalOut),
                'closing'=>$this->money($end),
                'movements_count'=>$n,
                'cut_at'=>$a->cut_at,
            ];
            $result[]=$row;
            foreach(['opening'=>$start,'entries'=>$in,'exits'=>$out,'closing'=>$end,
                     'internal_in'=>$internalIn,'internal_out'=>$internalOut] as $key=>$value){
                $totals[$key]+=$value;
            }
        }
        if($excluded)$warnings[]=$excluded.' conta(s) não activa(s) ou ainda sem abertura na data consultada ficaram fora dos saldos.';
        if(count($accounts)>1 && $totals['internal_in']!==$totals['internal_out']){
            $warnings[]='O saldo líquido de transferências internas é diferente de zero: pode existir apenas uma parte do período ou uma selecção de contas.';
        }
        return response()->json([
            'date'=>$d['date'],'account_id'=>$d['account_id']??null,
            'accounts'=>$result,
            'totals'=>collect($totals)->map(fn(int $v)=>$this->money($v)),
            'warnings'=>$warnings,
            'status'=>'conferencia_informativa',
            'notice'=>'Conferência contabilística somente leitura. Não efectua fecho, não bloqueia períodos e não comprova saldos bancários ou numerário físico.',
        ])->header('Cache-Control','no-store, private');
    }
}
