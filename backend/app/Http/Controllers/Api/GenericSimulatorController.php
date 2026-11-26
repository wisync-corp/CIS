<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LateCharge;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Simulador INDEPENDENTE de contratos. Calculadora genérica,
 * baseada no UserForm Parcelas.frm. Nenhum movimento, crédito, cobrança
 * ou alteração no banco de dados é gerado por esta API.
 */
final class GenericSimulatorController extends Controller
{
    private const TERMS=[
        '1 Semana'=>['periodo'=>15,'dias'=>7],
        '2 Semanas'=>['periodo'=>15,'dias'=>15],
        '3 Semanas'=>['periodo'=>30,'dias'=>21],
        'Mês'=>['periodo'=>30,'dias'=>30],
    ];
    private static function decimal(string $value, string $field): int
    {
        $value=trim($value);
        if(!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D',$value)) {
            throw ValidationException::withMessages([$field=>'Introduza um valor válido com até 2 casas decimais.']);
        }
        [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
        return (int)$whole*100+(int)str_pad($fraction,2,'0');
    }
    private static function amount(int $cents): string
    {
        return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }
    public function preview(Request $request)
    {
        $user=$request->user();
        \App\Support\Permissions::require($user,'ver');
        abort_unless(filter_var(env('CIS_PRIVATE_PREVIEW',false),FILTER_VALIDATE_BOOLEAN),503,
            'Simulador disponível apenas na pré-visualização privada.');
        if ($request->input('tipo_calculo')==='multa' && $request->has('mora')) {
            return $this->previewLateCharge($request);
        }
        $d=$request->validate([
            'montante'=>['required','string','max:24'],
            'prazo'=>['required','in:1 Semana,2 Semanas,3 Semanas,Mês'],
            'tipo_calculo'=>['required','in:prestacao,juros,multa'],
            'taxa_manual'=>['nullable','string','regex:/^(?:100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)$/D'],
        ]);
        $base=self::decimal($d['montante'],'montante');
        abort_unless($base>0,422,'Montante deve ser superior a zero.');
        $term=self::TERMS[$d['prazo']];
        abort_unless(Schema::hasTable('cis_tabela_juros'),503,'Tabela de juros de referência indisponível.');
        $rows=DB::table('cis_tabela_juros')
            ->where('limite_inferior','<=',self::amount($base))
            ->where('limite_superior','>=',self::amount($base))
            ->where('periodo_referencia',$term['periodo'])
            ->limit(2)->get(['taxa','limite_inferior','limite_superior']);
        abort_unless($rows->count()===1,409,
            'A faixa de juros não é única para o valor e prazo; não foi presumida uma taxa.');
        $rate=(string)($d['taxa_manual']??'');
        $manual=$rate!=='';
        $rate=$manual?$rate:(string)$rows[0]->taxa;
        $rateCents=self::decimal($rate,'taxa_manual');
        // cêntimos monetários * taxa em centésimos percentuais / 10000;
        // arredondamento explícito HALF_UP.
        $interest=intdiv($base*$rateCents+5000,10000);
        $total=$d['tipo_calculo']==='prestacao'?$base+$interest:$interest;
        return response()->json([
            'consulta_apenas'=>true,'contrato_associado'=>false,
            'movimento_registado'=>false,'valor_base'=>self::amount($base),
            'taxa_tabela'=>(string)$rows[0]->taxa,
            'taxa_aplicada'=>$rate,'taxa_manual'=>$manual,
            'prazo'=>$d['prazo'],'periodo_referencia'=>$term['periodo'],
            'dias_propostos'=>$term['dias'],'tipo_calculo'=>$d['tipo_calculo'],
            'acrescimo_calculado'=>self::amount($interest),
            'total_estimado'=>self::amount($total),
            'faixa'=>[(string)$rows[0]->limite_inferior,(string)$rows[0]->limite_superior],
            'arredondamento'=>'Duas casas, HALF_UP (requer homologação com Excel/VBA)',
            'aviso'=>'Simulação genérica, independente de contratos. Não corresponde a valor exigível, juros de mora aprovados, pagamento ou nova prestação registada.',
        ])->header('Cache-Control','no-store, private');
    }
    private function previewLateCharge(Request $request)
    {
        $rules=LateCharge::rules();
        $d=$request->validate([
            ...$rules,
            'mora'=>['required',...$rules['mora']],
            'mora.tipo'=>['required','in:fixo_unico,fixo_periodico,percentual_unico,percentual_periodico,escaloes,misto'],
            'mora.decisao'=>['sometimes','in:integral'],
            'montante'=>['required','string','max:24'],
            'capital'=>['required_if:mora.base,capital','nullable','string','max:24'],
            'vencimento'=>['required','date_format:Y-m-d'],
            'data_referencia'=>['required','date_format:Y-m-d'],
        ]);
        $balance=self::decimal($d['montante'],'montante');
        $capital=isset($d['capital'])?self::decimal($d['capital'],'capital'):0;
        abort_unless($balance>0,422,'O saldo da prestação deve ser superior a zero.');
        abort_if($capital>$balance,422,'O capital em dívida não pode exceder o saldo da prestação.');
        $due=CarbonImmutable::createFromFormat('!Y-m-d',$d['vencimento']);
        $reference=CarbonImmutable::createFromFormat('!Y-m-d',$d['data_referencia']);
        if (($d['mora']['tipo']??null)==='empresa') {
            $auto=\App\Support\CreditCharges::companyLateRule();
            abort_unless($auto,422,'A mora automática da empresa está desligada (Definições › Cobrança e juros de mora).');
            $d['mora']=$auto+array_intersect_key($d['mora'],array_flip(['decisao','valor_reduzido','motivo']));
        }
        $detail=LateCharge::calculate($d['mora'],$due,$reference,$capital,$balance,0,0,false,false);
        return response()->json([
            'tipo_calculo'=>'multa','consulta_apenas'=>true,'contrato_associado'=>false,'movimento_registado'=>false,
            'valor_base'=>self::amount($detail['base_centimos']),'saldo_prestacao'=>self::amount($balance),
            'vencimento'=>$d['vencimento'],'data_referencia'=>$d['data_referencia'],
            'dias_atraso'=>max(0,(int)$due->diffInDays($reference,false)),
            'mora_detalhe'=>$detail,'acrescimo_calculado'=>self::amount($detail['cobrado_centimos']),
            'total_estimado'=>self::amount($balance+$detail['cobrado_centimos']),
            'arredondamento'=>'Estimativa arredondada a duas casas decimais',
            'aviso'=>'Simulação informativa. O valor definitivo depende das condições do contrato e da data de pagamento.',
        ])->header('Cache-Control','no-store, private');
    }

}
