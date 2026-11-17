<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Prévia isolada dos cálculos apresentados pelo UserForm Parcelas.frm.
 * NÃO grava contratos, juros, saldos, recibos nem Extracto.
 * A entrada financeira tem de ser integrada depois com Extracto.account_id.
 */
final class ParcelasQuoteController extends Controller
{
    private const PERIODS = [
        '1 Semana' => ['periodo' => 15, 'dias' => 7],
        '2 Semanas' => ['periodo' => 15, 'dias' => 15],
        '3 Semanas' => ['periodo' => 30, 'dias' => 21],
        'Mês' => ['periodo' => 30, 'dias' => 30],
    ];

    private static function cents(string $value, string $field): int
    {
        $value=trim($value);
        if (!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', $value)) {
            throw ValidationException::withMessages([$field =>
                'Valor inválido: use números e até duas casas decimais com ponto.']);
        }
        [$integer,$fraction]=array_pad(explode('.',$value,2),2,'');
        return (int)$integer*100+(int)str_pad($fraction,2,'0');
    }

    private static function amount(int $cents): string
    {
        return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }

    /** Taxa % DECIMAL(8,2) expressa como centésimos de percentagem. */
    private static function rateHundredths(string $rate): int
    {
        if (!preg_match('/^\d{1,5}(?:\.\d{1,2})?$/D', $rate)) {
            throw ValidationException::withMessages(['taxa'=>'Taxa da tabela não reconhecida.']);
        }
        [$int,$frac]=array_pad(explode('.',$rate,2),2,'');
        return ((int)$int)*100+(int)str_pad($frac,2,'0');
    }

    private static function percent(int $baseCents, int $rateHundredths): int
    {
        // Calcula a percentagem em cêntimos, arredondamento HALF_UP.
        // A função Format VBA pode empregar arredondamento diferente em .005.
        return intdiv($baseCents*$rateHundredths + 5000, 10000);
    }

    public function preview(Request $request, string $numero, int $ordem)
    {
        $user=$request->user();
        abort_unless($user && $user->active && $user->role === 'administrador',403);
        abort_unless(
            filter_var(env('CIS_PRIVATE_PREVIEW',false), FILTER_VALIDATE_BOOLEAN)
            && filter_var(env('CIS_CONTRACTS_READ_APPROVED',false), FILTER_VALIDATE_BOOLEAN),
            503,'Simulação privada de prestações não autorizada.'
        );
        $d=$request->validate([
            'modo'=>['required','in:reparcelamento,multa'],
            'prazo'=>['required','in:1 Semana,2 Semanas,3 Semanas,Mês'],
            'valor_recebido'=>['required_if:modo,reparcelamento','nullable','string','max:24'],
        ]);
        abort_if(trim($numero)===''||mb_strlen($numero)>155,422,'Número de contrato inválido.');
        abort_unless(Schema::hasTable('cis_tabela_juros'),503,
            'A tabela de taxas de referência ainda não está disponível.');

        $rows=DB::connection('mysql_legacy')->table('Contractos')
            ->where('Nr do Contracto',$numero)->where('Ordem',$ordem)->limit(2)->get();
        abort_unless($rows->count()===1,409,'Prestação não encontrada ou duplicada.');
        $row=$rows->first();
        abort_unless(in_array($row->Estado,['Pendente','Pagamento Atrasado'],true),409,
            'A prestação não está pendente ou em atraso.');

        $mode=$d['modo'];
        $term=$d['prazo'];
        $period=self::PERIODS[$term]['periodo'];
        $days=self::PERIODS[$term]['dias'];
        $interest=self::cents((string)($row->{'Valor de Juros'}??'0'),'juros');
        $originalDue=self::cents((string)($row->{'Valor a Pagar'}??'0'),'valor_a_pagar');
        $paid=0;
        if($mode==='reparcelamento') {
            $paid=self::cents((string)($d['valor_recebido']??''),'valor_recebido');
            abort_unless($paid>0 && $paid<$originalDue && $paid>=$interest,422,
                'O pagamento parcial deve ser positivo, inferior ao devido e cobrir os juros.');
            // Melhoria conscientemente diferente do erro de VBA:
            // Cobrança.frm lê txt_vpago de Valor Pago (normalmente zero)
            // e calcula c_vrem=txt_vpago-myValue. Para a prévia CAPTA,
            // usa-se a dívida de parcela Valor a Pagar menos valor recebido.
            $base=$originalDue-$paid;
        } else {
            // Parcelas.frm c_multa=True recebe c_vrem=v_juros;
            // txt_vpagar = txt_vrem * taxa/100 (sem somar principal).
            $base=$interest;
        }
        abort_unless($base>0,409,'A base da simulação não pode ser nula.');

        // Replicar explicitamente a escolha de período de faixa:
        // 1/2 Semanas -> Period 15; 3 Semanas/Mês -> Period 30.
        $matches=DB::table('cis_tabela_juros')
            ->where('limite_inferior','<=',self::amount($base))
            ->where('limite_superior','>=',self::amount($base))
            ->where('periodo_referencia',$period)
            ->limit(2)->get(['taxa','limite_inferior','limite_superior']);
        abort_unless($matches->count()===1,409,
            'A tabela de taxas não tem uma faixa única para o montante e período. Não foi presumida qualquer taxa.');
        $rate=(string)$matches[0]->taxa;
        $rateHundredths=self::rateHundredths($rate);
        abort_unless($rateHundredths<=10000,409,'Taxa superior a 100% requer homologação.');
        $computedInterest=self::percent($base,$rateHundredths);
        $projected=$mode==='multa'?$computedInterest:$base+$computedInterest;
        $contractPenalty=(string)($row->{'% de Multa'}??'');
        $contractPenaltyHundredths=$contractPenalty!==''?self::rateHundredths($contractPenalty):null;
        $contractPenaltyAmount=$contractPenaltyHundredths===null?null:
            self::percent($base,$contractPenaltyHundredths);

        $response=[
            'consulta_apenas'=>true,'movimento_registado'=>false,'confirmado'=>false,
            'modo'=>$mode,'prazo'=>$term,'periodo_referencia'=>$period,
            'dias_propostos'=>$days,
            'contrato'=>$numero,'ordem'=>$ordem,'parcela'=>$row->{'Nº da Parcela'},
            'base'=>self::amount($base),
            'valor_recebido'=>$mode==='reparcelamento'?self::amount($paid):null,
            'valor_a_pagar_original'=>self::amount($originalDue),
            'juros_originais'=>self::amount($interest),
            'taxa_faixa'=>$rate,
            'limite_inferior'=>(string)$matches[0]->limite_inferior,
            'limite_superior'=>(string)$matches[0]->limite_superior,
            'juros_ou_multa_calculados'=>self::amount($computedInterest),
            'total_calculado'=>self::amount($projected),
            'calculo'=>'Parcelas.frm: '.($mode==='multa'?'base × taxa/100':'base × (1 + taxa/100)'),
            'arredondamento'=>'2 casas decimais, HALF_UP; sujeito a homologação versus Format do VBA',
            'aviso'=>$mode==='multa'
                ?'O Parcelas.frm consulta a taxa por faixa do valor dos juros, mesmo no modo multa. A taxa contratual de multa pode divergir. Não cobrar nem lançar esta prévia.'
                :'Valor de referência = Valor a Pagar - recebimento parcial. O VBA original usa txt_vpago alimentado por Valor Pago, podendo produzir saldo negativo. Prévia sem criar/recalendarizar prestações.'
        ];
        if($mode==='multa') {
            $response['taxa_multa_contrato']=$contractPenalty!==''?$contractPenalty:null;
            $response['multa_pela_taxa_contratual']=$contractPenaltyAmount===null?null:
                self::amount($contractPenaltyAmount);
            $response['metodos_divergentes']=$contractPenaltyHundredths!==null
                && $contractPenaltyHundredths!==$rateHundredths;
        }
        return response()->json($response)->header('Cache-Control','no-store, private');
    }
}
