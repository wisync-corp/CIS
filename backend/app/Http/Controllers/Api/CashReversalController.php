<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estorno integral de movimentos gerais da Caixa.
 *
 * Livro ÚNICO: Extracto. Registo original imutável; estorno = novo
 * lançamento de sentido contrário, referenciado com operation_key
 * determinística. Transferência revertida com duas linhas na MESMA
 * transacção InnoDB, bloqueando ambas as contas em ordem crescente.
 *
 * Não trata cobrança/desembolso de contratos. Inactivo por omissão.
 */
final class CashReversalController extends Controller
{
    private function access(Request $request, bool $write): void
    {
        $user=$request->user();
        abort_unless($user && $user->active && $user->role==='administrador',403);
        abort_unless(filter_var(env('CIS_PRIVATE_PREVIEW',false),FILTER_VALIDATE_BOOLEAN),503);
        if($write){
            foreach(['CIS_CASH_WRITES_APPROVED','CIS_CASH_LEDGER_POSTING_APPROVED','CIS_CASH_REVERSALS_APPROVED'] as $flag){
                abort_unless(filter_var(env($flag,false),FILTER_VALIDATE_BOOLEAN),503,
                    'Estornos ainda não homologados; escrita bloqueada.');
            }
        }
        abort_unless(Schema::hasTable('cis_cash_accounts')
            && Schema::hasColumn('Extracto','account_id')
            && Schema::hasColumn('Extracto','operation_key'),503);
        abort_unless(!Schema::hasTable('cis_cash_movements')
            && !Schema::hasTable('cis_cash_links'),503);
    }

    private function cents(string $amount): int
    {
        abort_unless(preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/D',$amount)===1,409,
            'Montante incompatível com a precisão financeira.');
        [$whole,$fraction]=array_pad(explode('.',$amount,2),2,'');
        return (int)$whole*100+(int)str_pad($fraction,2,'0');
    }
    private function money(int $cents):string
    {
        return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }
    private function opposite(string $kind):string
    {
        abort_unless(in_array($kind,['Entradas','Saídas'],true),409,
            'Tipo original de movimento não reconhecido.');
        return $kind==='Entradas'?'Saídas':'Entradas';
    }

    /**
     * Reconhece só operações de Caixa geradas pelo endpoint geral.
     * Bloqueia reversões de estornos, linhas legadas e contratos.
     */
    private function originals($db,int $nr):array
    {
        $origin=$db->table('Extracto')->where('Nr',$nr)->first();
        abort_unless($origin,404,'Lançamento original inexistente.');
        abort_unless($origin->account_id!==null
            && preg_match('/^cash:([0-9a-f-]{36}):([12])$/i',(string)$origin->operation_key,$match)===1
            && (string)($origin->Contracto??'')===''
            && (int)($origin->Parcela??0)===0,409,
            'Apenas movimentos gerais identificados da Caixa podem ser estornados.');
        $value=$this->cents((string)$origin->Valor);
        abort_unless($value>0,409,'Movimento sem montante positivo.');
        $this->opposite((string)$origin->{'Tipo de Movimento'});
        if(str_starts_with((string)$origin->{'Descrição'},'[CAIXA:')){
            abort_unless($match[2]==='1',409,'Lançamento de Caixa com chave inesperada.');
            return ['single',[$origin],$nr];
        }
        abort_unless(str_starts_with((string)$origin->{'Descrição'},'[TRANSFER '),409,
            'Movimento não reconhecido como entrada, saída ou transferência geral.');
        $root='cash:'.$match[1];
        $legs=$db->table('Extracto')->whereIn('operation_key',[$root.':1',$root.':2'])
            ->get()->keyBy('operation_key');
        abort_unless($legs->count()===2,409,'Transferência original incompleta.');
        $out=$legs->get($root.':1');$in=$legs->get($root.':2');
        abort_unless($out && $in
            && (string)$out->{'Tipo de Movimento'}==='Saídas'
            && (string)$in->{'Tipo de Movimento'}==='Entradas'
            && (int)$out->account_id!==(int)$in->account_id
            && str_starts_with((string)$out->{'Descrição'},'[TRANSFER SAIDA ')
            && str_starts_with((string)$in->{'Descrição'},'[TRANSFER ENTRADA ')
            && $this->cents((string)$out->Valor)===$this->cents((string)$in->Valor)
            && (string)($out->Contracto??'')==='' && (string)($in->Contracto??'')===''
            && (int)($out->Parcela??0)===0 && (int)($in->Parcela??0)===0,409,
            'As duas pernas da transferência original não correspondem.');
        return ['transfer',[$out,$in],(int)$out->Nr];
    }

    private function keys(string $mode,int $anchor):array
    {
        return $mode==='single'
            ?['cash:reverse:single:'.$anchor.':1']
            :['cash:reverse:transfer:'.$anchor.':1','cash:reverse:transfer:'.$anchor.':2'];
    }

    private function checkAlready($db,array $keys):bool
    {
        return $db->table('Extracto')->whereIn('operation_key',$keys)->exists();
    }

    private function balances($db,array $originals):array
    {
        $ids=array_values(array_unique(array_map(fn($x)=>(int)$x->account_id,$originals)));
        sort($ids,SORT_NUMERIC);
        $accounts=[];
        foreach($ids as $id){
            $a=$db->table('cis_cash_accounts')->where('id',$id)->lockForUpdate()->first();
            abort_unless($a && $a->status==='activa' && $a->currency==='MZN'
                && $a->opening_balance!==null && $a->cut_at!==null
                && $a->validated_at!==null && $a->opening_extracto_nr!==null,409,
                'Conta não activa ou com abertura incompleta.');
            $accounts[$id]=$a;
        }
        $before=[];
        foreach($accounts as $id=>$a){
            $balance=$this->cents((string)$a->opening_balance);
            foreach($db->table('Extracto')->where('account_id',$id)
                ->where('Nr','>',$a->opening_extracto_nr)
                ->get(['Valor','Tipo de Movimento']) as $row){
                $c=$this->cents((string)$row->Valor);
                abort_unless(in_array((string)$row->{'Tipo de Movimento'},['Entradas','Saídas'],true),409,
                    'Movimento de natureza desconhecida no saldo da conta.');
                $balance+=$row->{'Tipo de Movimento'}==='Entradas'?$c:-$c;
            }
            abort_unless($balance>=0,409,'Saldo actual inconsistente; estorno bloqueado.');
            $before[$id]=$balance;
        }
        foreach($originals as $o){
            abort_unless((int)$o->Nr>(int)$accounts[(int)$o->account_id]->opening_extracto_nr,409,
                'Movimento anterior à abertura documentada da conta.');
        }
        return $before;
    }

    public function preview(Request $request,int $nr)
    {
        $this->access($request,false);
        $db=DB::connection();
        [$mode,$originals,$anchor]=$this->originals($db,$nr);
        $already=$this->checkAlready($db,$this->keys($mode,$anchor));
        // Prévia não bloqueia saldos nem reserva dinheiro; a escrita
        // revalida tudo em transacção com lock.
        $accounts=$db->table('cis_cash_accounts')
            ->whereIn('id',array_map(fn($o)=>(int)$o->account_id,$originals))
            ->get()->keyBy('id');
        $items=[];
        foreach($originals as $row){
            $a=$accounts->get((int)$row->account_id);
            $type=$this->opposite((string)$row->{'Tipo de Movimento'});
            $items[]=[
                'original_nr'=>(int)$row->Nr,
                'account_id'=>(int)$row->account_id,
                'account_code'=>$a?->code,
                'original_type'=>(string)$row->{'Tipo de Movimento'},
                'reversal_type'=>$type,
                'amount'=>$this->money($this->cents((string)$row->Valor)),
            ];
        }
        return response()->json([
            'consulta_apenas'=>true,'already_reversed'=>$already,
            'can_submit'=>!$already
                && filter_var(env('CIS_CASH_REVERSALS_APPROVED',false),FILTER_VALIDATE_BOOLEAN)
                && filter_var(env('CIS_CASH_WRITES_APPROVED',false),FILTER_VALIDATE_BOOLEAN)
                && filter_var(env('CIS_CASH_LEDGER_POSTING_APPROVED',false),FILTER_VALIDATE_BOOLEAN),
            'mode'=>$mode,'origin_nr'=>$anchor,'legs'=>$items,
            'notice'=>'Prévia sem gravação. A confirmação exigirá motivo, referência documental, autorização activa e saldo suficiente nas contas debitadas.',
        ])->header('Cache-Control','no-store, private');
    }

    public function reverse(Request $request,int $nr)
    {
        $this->access($request,true);
        $input=$request->validate([
            'reason'=>['required','string','min:10','max:80','regex:/^[^\r\n|]+$/u'],
            'evidence_reference'=>['required','string','min:6','max:65','regex:/^[^\r\n|]+$/u'],
            'confirmation_phrase'=>['required','in:CONFIRMO-ESTORNO-REAL'],
        ]);
        $db=DB::connection('mysql_cash_ledger');
        abort_unless($db->getDatabaseName()===DB::connection()->getDatabaseName(),503,
            'Escritor aponta para base de dados diferente.');
        return $db->transaction(function ()use($db,$nr,$input,$request){
            [$mode,$originals,$anchor]=$this->originals($db,$nr);
            $keys=$this->keys($mode,$anchor);
            // A lock order por conta é a mesma de entradas, saídas e transferências.
            $before=$this->balances($db,$originals);
            abort_if($this->checkAlready($db,$keys),409,
                'Esta operação já foi estornada; consulte o Extracto antes de repetir.');
            $created=[];
            $after=$before;
            foreach($originals as $i=>$original){
                $id=(int)$original->account_id;
                $amount=$this->cents((string)$original->Valor);
                $type=$this->opposite((string)$original->{'Tipo de Movimento'});
                $after[$id]+=$type==='Entradas'?$amount:-$amount;
                abort_unless($after[$id]>=0,409,
                    'Saldo insuficiente para estornar a entrada ou a transferência.');
                $description='[ESTORNO '.($mode==='transfer'?'TRANSFER':'CAIXA').
                    ' ORIG:'.(int)$original->Nr.'] '.trim($input['reason']).
                    ' | REF:'.trim($input['evidence_reference']);
                $created[]=$db->table('Extracto')->insertGetId([
                    'account_id'=>$id,'operation_key'=>$keys[$i],
                    'Tipo de Movimento'=>$type,'Valor'=>$this->money($amount),
                    'Data da Operação'=>now('Africa/Maputo')->format('Y-m-d'),
                    'Descrição'=>$description,
                    'Contracto'=>'','Cliente'=>'CAIXA GERAL','Parcela'=>0,
                    'usuario'=>(string)$request->user()->id,
                ],'Nr');
            }
            return response()->json([
                'reversed'=>true,'origin_nr'=>$anchor,'mode'=>$mode,
                'reversal_extracto_nrs'=>$created,
                'notice'=>'Estorno registado por lançamentos de sentido contrário. Originais preservados.',
            ],201)->header('Cache-Control','no-store, private');
        },3);
    }
}
