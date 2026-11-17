<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonImmutable;

/**
 * Prévia (NUNCA escrita) dos caminhos do UserForm Cobrança.frm.
 * Os resultados NÃO são uma liquidação aprovada nem geram recibo/Extracto.
 * O UserForm Parcelas.frm foi recebido e as suas regras de cálculo
 * estão no ParcelasQuoteController. A recalendarização financeira real
 * e o remanescente continuam sem homologação e sem autorização de escrita.
 */
final class CollectionScenarioPreviewController extends Controller
{
    private static function cents($input, string $field): int
    {
        $value=trim((string)$input);
        if (!preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?$/',$value)) {
            throw ValidationException::withMessages([$field=>'Valor monetário inválido (até 2 casas decimais).']);
        }
        $parts=explode('.',$value,2);
        return (int)$parts[0]*100+(int)str_pad($parts[1]??'0',2,'0');
    }
    private static function fmt(int $value): string
    {
        $neg=$value<0?'-':'';
        $abs=abs($value);
        return $neg.intdiv($abs,100).'.'.str_pad((string)($abs%100),2,'0',STR_PAD_LEFT);
    }

    public function preview(Request $request, string $numero, int $ordem)
    {
        $user=$request->user();
        abort_unless($user && $user->active && $user->role==='administrador',403);
        abort_unless(filter_var(env('CIS_PRIVATE_PREVIEW',false),FILTER_VALIDATE_BOOLEAN)
            && filter_var(env('CIS_CONTRACTS_READ_APPROVED',false),FILTER_VALIDATE_BOOLEAN),503,
            'Consulta financeira privada não autorizada.');
        $d=$request->validate([
            'operacao'=>['required','in:parcial,renovar_juros,capitalizar,remanescente,renovar_credito,actualizar_data,finalizar_contrato'],
            'valor_pago'=>['required_if:operacao,parcial','nullable','string','max:20'],
            'data_pagamento'=>['required_if:operacao,actualizar_data','nullable','date_format:Y-m-d'],
            'periodo_referencia'=>['required_if:operacao,capitalizar','nullable','integer','in:15,30'],
        ]);
        abort_if(trim($numero)==='' || mb_strlen($numero)>155,422,'Identificador de contrato inválido.');
        $db=DB::connection('mysql_legacy');
        $rows=$db->table('Contractos')->where('Nr do Contracto',$numero)
            ->where('Ordem',$ordem)->limit(2)->get();
        abort_unless($rows->count()===1,409,'Prestação inexistente ou duplicada.');
        $r=$rows->first();
        $operation=$d['operacao'];
        $base=[
            'consulta_apenas'=>true,
            'operacao'=>$operation,
            'contrato'=>$numero,
            'parcela'=>$r->{'Nº da Parcela'},
            'ordem'=>$ordem,
            'estado_actual'=>$r->Estado,
            'exige_aprovacao'=>true,
            'comprovativo_verificado'=>false,
            'movimento_registado'=>false,
        ];
        $supported=['Pendente','Pagamento Atrasado'];
        if (in_array($operation,['parcial','renovar_juros','capitalizar','actualizar_data','remanescente'],true)) {
            abort_unless(in_array($r->Estado,$supported,true),409,
                'Simulação limitada às prestações pendentes ou atrasadas.');
        }
        $capital=self::cents((string)($r->Capital??0),'capital');
        if ($capital===0) $capital=self::cents((string)($r->{'Quantia Creditada'}??0),'capital');
        $interest=self::cents((string)($r->{'Valor de Juros'}??0),'juros');
        $due=self::cents((string)($r->{'Valor a Pagar'}??0),'valor_a_pagar');
        if (in_array($operation,['parcial','renovar_juros','capitalizar','actualizar_data'],true)) {
            abort_unless($capital>0 && $due>0,409,
                'Capital ou valor a pagar incompatível com simulação.');
        }

        if ($operation==='parcial') {
            $paid=self::cents($d['valor_pago']??'','valor_pago');
            if ($paid<$interest || $paid<=0 || $paid>=$due) {
                throw ValidationException::withMessages(['valor_pago'=>
                    'O parcial tem de ser positivo, cobrir pelo menos os juros e ser inferior ao valor devido.']);
            }
            $interestPaid=min($interest,$paid);
            $amortization=min($capital,max(0,$paid-$interestPaid));
            return response()->json($base+[
                'resultado'=>'indicativo_sem_recalendarizacao',
                'valor_pago'=>self::fmt($paid),
                'juros_cobertos'=>self::fmt($interestPaid),
                'amortizacao'=>self::fmt($amortization),
                'capital_historico'=>self::fmt($capital),
                'juros_historicos'=>self::fmt($interest),
                'diferenca_aritmetica'=>self::fmt($due-$paid),
                'aviso'=>'Ordem juros primeiro, capital depois, conforme VBA. NÃO corresponde à prestação remanescente homologada, que exige regras de recalendarização homologadas.',
            ])->header('Cache-Control','no-store, private');
        }
        if ($operation==='renovar_juros') {
            return response()->json($base+[
                'resultado'=>'indicativo_sem_renovacao',
                'capital'=>self::fmt($capital),
                'amortizacao'=>'0.00',
                'juros_actual'=>self::fmt($interest),
                'juros_novo'=>self::fmt($interest*2),
                'prestacao_nova'=>self::fmt($capital+($interest*2)),
                'aviso'=>'A rotina VBA duplica os juros na renovação. Sem pagamento e sem movimento no Extracto. Prazo, aceitação e novo vencimento requerem validação.',
            ])->header('Cache-Control','no-store, private');
        }
        if ($operation==='capitalizar') {
            $newCapital=$capital+$interest;
            $period=(int)$d['periodo_referencia'];
            // O VBA prefere período 30 e usa a primeira faixa como fallback.
            // Em vez de escolher uma taxa silenciosamente, exigir o período.
            abort_unless(\Illuminate\Support\Facades\Schema::hasTable('cis_tabela_juros'),503,
                'Tabela de referência de juros não instalada.');
            $matches=DB::table('cis_tabela_juros')
                ->where('limite_inferior','<=',self::fmt($newCapital))
                ->where('limite_superior','>=',self::fmt($newCapital))
                ->where('periodo_referencia',$period)
                ->get(['taxa','limite_inferior','limite_superior']);
            abort_unless($matches->count()===1,409,
                'Capitalização sem faixa única para o período seleccionado: requer revisão da tabela de taxas.');
            $selected=$matches->first();
            $newInterest=(int)round($newCapital*(float)$selected->taxa/100);
            return response()->json($base+[
                'resultado'=>'indicativo_sem_capitalizacao',
                'capital_anterior'=>self::fmt($capital),
                'juros_anteriores'=>self::fmt($interest),
                'capital_novo'=>self::fmt($newCapital),
                'amortizacao'=>'0.00',
                'taxa'=>(string)$selected->taxa,
                'periodo_referencia'=>$period,
                'faixa'=>[(string)$selected->limite_inferior,(string)$selected->limite_superior],
                'juros_novos'=>self::fmt($newInterest),
                'prestacao_nova'=>self::fmt($newCapital+$newInterest),
                'aviso'=>'Taxa consultada na cis_tabela_juros por faixa e período explícito. Prévia indicativa, sem alteração de capital ou contrato.',
            ])->header('Cache-Control','no-store, private');
        }
        if ($operation==='actualizar_data') {
            $candidate=$d['data_pagamento'];
            abort_if($candidate>now('Africa/Maputo')->toDateString(),422,
                'Não é permitida uma data futura.');
            $dueDate=CarbonImmutable::parse($r->{'Data de Cobrança'})->startOfDay();
            $paidDate=CarbonImmutable::createFromFormat('!Y-m-d',$candidate);
            $daysLate=max(0,(int)$dueDate->diffInDays($paidDate,false));
            return response()->json($base+[
                'resultado'=>'revisao_informativa',
                'data_revisao'=>$candidate,
                'dias_atraso'=>$daysLate,
                'estado_proposto'=>$daysLate>0?'Pagamento Atrasado':'Pendente',
                'tipo_juro'=>$r->{'Tipo de Juro'}??null,
                'aviso'=>'A revisão de data no VBA também recalcula juros variáveis; nenhuma data, estado ou valor foi alterado. Exige homologação de política retroactiva.',
            ])->header('Cache-Control','no-store, private');
        }
        if ($operation==='remanescente') {
            $late=$db->table('Contractos')->where('Nr do Contracto',$numero)
                ->where('Estado','Pagamento Atrasado')->count();
            return response()->json($base+[
                'resultado'=>'analise_pre_condicoes',
                'prestacoes_em_atraso'=>$late,
                'elegivel_para_analise'=>$late===0,
                'aviso'=>'Não calcular ou liquidar remanescente sem conciliar parciais, calendário e pagamento real; Os formulários estão disponíveis, mas o tratamento de parciais sucessivos e o lançamento ainda exigem homologação.',
            ])->header('Cache-Control','no-store, private');
        }
        if ($operation==='finalizar_contrato') {
            $notPaid=$db->table('Contractos')->where('Nr do Contracto',$numero)
                ->whereNotIn('Estado',['Pago','Pago em Atraso'])->count();
            return response()->json($base+[
                'resultado'=>'analise_pre_condicoes','prestacoes_nao_liquidadas'=>$notPaid,
                'elegivel_para_analise'=>$notPaid===0,
                'aviso'=>'Ausência de pendências não prova conciliação de recebimentos; verificar Extracto.account_id antes de encerrar.',
            ])->header('Cache-Control','no-store, private');
        }
        return response()->json($base+[
            'resultado'=>'requer_novo_contrato',
            'aviso'=>'Renovar empréstimo cria novo contrato, não renova somente juros. É necessária nova aprovação e desembolso a partir de conta da Caixa.',
        ])->header('Cache-Control','no-store, private');
    }
}
