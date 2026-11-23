<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\CarbonImmutable;

/**
 * Relatório do fluxo de Caixa REAL por período — somente SELECT.
 * A fonte é Extracto.account_id e o saldo de abertura é cis_cash_accounts.
 * Não atribuir movimentos históricos que não possuem conta.
 */
final class CashReportController extends Controller
{
    private function money(int $cents): string
    {
        return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }

    private function cents(string $amount): int
    {
        abort_unless(preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,2})?$/D',$amount)===1,409,
            'Valor financeiro incompatível com precisão de cêntimos.');
        [$n,$d]=array_pad(explode('.',$amount,2),2,'');
        return (int)$n*100+(int)str_pad($d,2,'0');
    }

    public function index(Request $request)
    {
        $user=$request->user();
        \App\Support\Permissions::require($user,'caixa');
        abort_unless(filter_var(env('CIS_PRIVATE_PREVIEW',false),FILTER_VALIDATE_BOOLEAN),503);
        abort_unless(Schema::hasTable('cis_cash_accounts')
            && Schema::hasColumn('Extracto','account_id'),503);

        $data=$request->validate([
            'from'=>['required','date_format:Y-m-d'],
            'to'=>['required','date_format:Y-m-d','after_or_equal:from'],
            'account_id'=>['nullable','integer','min:1'],
        ]);
        $start=CarbonImmutable::createFromFormat('!Y-m-d',$data['from'],'Africa/Maputo');
        $end=CarbonImmutable::createFromFormat('!Y-m-d',$data['to'],'Africa/Maputo');
        abort_unless($start && $end && $start->diffInDays($end)<=366,422,
            'O período de consulta não pode exceder 366 dias.');
        abort_unless($end->lessThanOrEqualTo(CarbonImmutable::now('Africa/Maputo')->endOfDay()),422,
            'A data final não pode estar no futuro.');

        $accounts=DB::table('cis_cash_accounts')
            ->when(isset($data['account_id']),fn($q)=>$q->where('id',$data['account_id']))
            ->orderBy('code')->get(['id','code','name','status','cut_at','opening_extracto_nr']);
        abort_if(isset($data['account_id']) && $accounts->isEmpty(),404,'Conta financeira inexistente.');
        $byAccount=$accounts->keyBy('id');
        $ids=$accounts->pluck('id')->all();

        $query=DB::table('Extracto')->whereIn('account_id',$ids)
            ->whereDate('Data da Operação','>=',$data['from'])
            ->whereDate('Data da Operação','<=',$data['to'])
            ->whereNotNull('account_id');

        $entries=0;$exits=0;$internalIn=0;$internalOut=0;
        $count=0;$categoryCents=[];
        $rows=$query->orderBy('Nr')->select([
            'Nr','account_id','Valor','Tipo de Movimento','Descrição',
            ...(Schema::hasColumn('Extracto','operation_key')?['operation_key']:[]),
        ])->cursor();

        foreach($rows as $row){
            $account=$byAccount->get($row->account_id);
            if(!$account)continue;
            // O reporte inclui apenas movimentos a partir da abertura
            // documental da conta; contratos históricos não atribuídos ficam fora.
            if($account->opening_extracto_nr===null || (int)$row->Nr<=(int)$account->opening_extracto_nr)continue;
            $value=$this->cents((string)$row->Valor);
            $kind=(string)$row->{'Tipo de Movimento'};
            abort_unless(in_array($kind,['Entradas','Saídas'],true),409,
                'Movimento com natureza financeira não reconhecida.');
            $isTransfer=isset($row->operation_key)
                && (
                    (preg_match('/^cash:[0-9a-f-]{36}:[12]$/i',(string)$row->operation_key)
                        && str_starts_with((string)$row->{'Descrição'},'[TRANSFER '))
                    || (preg_match('/^cash:reverse:transfer:[0-9]+:[12]$/',(string)$row->operation_key)
                        && str_starts_with((string)$row->{'Descrição'},'[ESTORNO TRANSFER '))
                );
            if($isTransfer){
                if($kind==='Entradas')$internalIn+=$value;else $internalOut+=$value;
            }elseif($kind==='Entradas')$entries+=$value;
            else $exits+=$value;
            $count++;
            $name=$isTransfer?'Transferência interna'
                :(str_starts_with((string)($row->operation_key??''),'cash:reverse:')
                    ?'Estorno financeiro':'Operação geral');
            if(!$isTransfer && preg_match('/^\[CAIXA:([^]]+)\]/u',(string)$row->{'Descrição'},$m))$name=$m[1];
            $categoryCents[$name]=($categoryCents[$name]??0)+($kind==='Entradas'?$value:-$value);
        }
        ksort($categoryCents,SORT_NATURAL);
        return response()->json([
            'from'=>$data['from'],'to'=>$data['to'],
            'account_id'=>$data['account_id']??null,
            'accounts_count'=>$accounts->count(),
            'movements_count'=>$count,
            'entries'=>$this->money($entries),
            'exits'=>$this->money($exits),
            'net_external'=>$this->moneySigned($entries-$exits),
            'internal_in'=>$this->money($internalIn),
            'internal_out'=>$this->money($internalOut),
            'internal_net'=>$this->moneySigned($internalIn-$internalOut),
            'categories'=>collect($categoryCents)->map(fn($v)=>$this->moneySigned($v)),
            'notice'=>'Relatório somente de movimentos com account_id no Extracto. Transferências internas não são receitas nem despesas externas. Não substitui conciliação bancária.',
        ])->header('Cache-Control','no-store, private');
    }

    private function moneySigned(int $cents): string
    {
        return ($cents<0?'-':'').$this->money(abs($cents));
    }
}
