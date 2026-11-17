<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LegacyContractPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonImmutable;

/**
 * Apenas liquidação TOTAL de uma prestação CIS nova.
 * Cobrança.frm: Entrada no Extracto, parcela Pago/Pago em Atraso,
 * juros variáveis conforme dias entre início e pagamento.
 * Pagamentos parciais/renovações do VBA não são replicados sem reconciliação.
 */
final class ContractCollectionController extends Controller
{
    private function admin(Request $request): void
    {
        $u=$request->user();
        abort_unless($u && $u->active && $u->role==='administrador',403);
        foreach (['CIS_PRIVATE_PREVIEW','CIS_CONTRACTS_READ_APPROVED'] as $flag) {
            abort_unless(filter_var(env($flag,false),FILTER_VALIDATE_BOOLEAN),503);
        }
    }

    private function quote(object $row, string $date, bool $multa): array
    {
        $today=CarbonImmutable::createFromFormat('!Y-m-d',$date);
        $due=CarbonImmutable::parse($row->{'Data de Cobrança'})->startOfDay();
        $start=CarbonImmutable::parse($row->{'Data de Inicio'})->startOfDay();
        $delay=max(0,(int)$due->diffInDays($today,false));
        $principal=(int)$row->{'Amortização'};
        $rate=(float)$row->{'% de Juro'};
        $interest=$row->{'Tipo de Juro'}==='Variável'
            ? LegacyContractPricing::upwards((int)$row->Capital*$rate/100*
                max(0,(int)$start->diffInDays($today,false)))
            : (int)$row->{'Valor de Juros'};
        $base=$row->{'Tipo de Juro'}==='Variável'?$principal+$interest:
            (int)$row->{'Valor a Pagar'};
        // Parcelas.frm c_multa=True, c_vrem=valor de juros:
        // multa aplica-se ao juro, nunca é inferida sobre todo o capital.
        $fine=$multa && $delay>0
            ? LegacyContractPricing::upwards($interest*(float)$row->{'% de Multa'}/100):0;
        return [
            'parcela'=>(int)$row->{'Nº da Parcela'},
            'dias_atraso'=>$delay,
            'juros'=>$interest,
            'multa'=>$fine,
            'total'=>$base+$fine,
            'estado'=>$delay>0?'Pago em Atraso':'Pago',
            'data_pagamento'=>$date,
            'aviso'=>'Liquidação total apenas. Multa opcional sobre juros, conforme Parcelas.frm.',
        ];
    }

    public function preview(Request $request, string $numero, int $ordem)
    {
        $this->admin($request);
        $data=$request->validate([
            'data_pagamento'=>['required','date_format:Y-m-d'],
            'cobrar_multa'=>['required','boolean'],
        ]);
        $row=DB::connection('mysql_legacy')->table('Contractos')
            ->where('Nr do Contracto',$numero)->where('Ordem',$ordem)->first();
        abort_unless($row,404,'Prestação não encontrada.');
        abort_unless($row->{'Estratégia de Cálculo'}!==null && $row->{'Estratégia de Cálculo'}!=='',
            409,'A cobrança de contratos históricos aguarda reconciliação de regras.');
        abort_unless(in_array($row->Estado,['Pendente','Pagamento Atrasado'],true),409,
            'A prestação já não está pendente.');
        abort_if($data['data_pagamento']!==now('Africa/Maputo')->toDateString(),422,
            'A data de pagamento real tem de ser hoje em Moçambique.');
        return response()->json($this->quote($row,$data['data_pagamento'],
            (bool)$data['cobrar_multa']))->header('Cache-Control','no-store, private');
    }

    public function collect(Request $request, string $numero, int $ordem)
    {
        $this->admin($request);
        // INTEGRIDADE: o escritor legado não exige account_id; a flag antiga
        // NÃO pode lançar cobrança sem conta activa, saldo de abertura e
        // confirmação real de entrada. Reimplementar pela Caixa, atomicamente.
        abort(503,'Cobrança real suspensa: é obrigatório associar a entrada a uma conta financeira activa da Caixa. A simulação permanece disponível.');
        abort_unless(filter_var(env('CIS_COLLECTION_WRITES_APPROVED',false),FILTER_VALIDATE_BOOLEAN),503,
            'A cobrança real ainda não foi activada no servidor.');
        $data=$request->validate([
            'data_pagamento'=>['required','date_format:Y-m-d'],
            'cobrar_multa'=>['required','boolean'],
            'valor_recebido'=>['required','integer','min:1'],
            'confirmacao_recebimento'=>['required','accepted'],
        ]);
        abort_if($data['data_pagamento']!==now('Africa/Maputo')->toDateString(),422,
            'A cobrança efectiva deve ser registada com a data de hoje.');
        $db=DB::connection('mysql_contract_writer');
        $result=$db->transaction(function () use ($db,$data,$numero,$ordem,$request) {
            $rows=$db->table('Contractos')->where('Nr do Contracto',$numero)
                ->where('Ordem',$ordem)->lockForUpdate()->get();
            abort_unless($rows->count()===1,409,'Prestação não encontrada ou identificador duplicado.');
            $row=$rows[0];
            abort_unless($row->{'Estratégia de Cálculo'}!==null
                && trim((string)$row->{'Estratégia de Cálculo'})!=='',409,
                'Cobrança real limitada a contratos CIS novos.');
            abort_unless(in_array($row->Estado,['Pendente','Pagamento Atrasado'],true),409,
                'Pagamento já concluído ou em estado incompatível.');
            abort_unless((float)($row->{'Valor Pago'}??0)===0.0,409,
                'Existe valor pago no histórico desta prestação; cobrança bloqueada.');
            abort_if($db->table('Extracto')->where('Contracto',$numero)
                ->where('Parcela',$row->{'Nº da Parcela'})
                ->where('Tipo de Movimento','Entradas')->exists(),409,
                'Movimento de entrada já existente para esta parcela.');
            $calc=$this->quote($row,$data['data_pagamento'],(bool)$data['cobrar_multa']);
            if ($calc['total']!==(int)$data['valor_recebido']) {
                throw ValidationException::withMessages(['valor_recebido'=>
                    'O valor recebido não corresponde ao valor devido calculado no servidor.']);
            }
            abort_unless($calc['total']>0&&$calc['total']<=16777216,422,'Valor inválido.');
            $updated=$db->table('Contractos')->where('Ordem',$ordem)
                ->where('Nr do Contracto',$numero)
                ->whereIn('Estado',['Pendente','Pagamento Atrasado'])
                ->update([
                    'Valor de Juros'=>$calc['juros'],
                    'Valor de Multa'=>$calc['multa'],
                    'Valor a Pagar'=>$calc['total'],
                    'Valor Pago'=>$calc['total'],
                    'Data de Pagamento'=>$calc['data_pagamento'],
                    'Estado'=>$calc['estado'],
                    'Dias'=>$calc['dias_atraso'],
                    'Situação de Pagamento'=>'Pagamento Completo',
                ]);
            abort_unless($updated===1,409,'A prestação foi alterada por outra operação.');
            $db->table('Extracto')->insert([
                'Contracto'=>$numero,
                'Cliente'=>$row->{'Nome do Cliente'},
                'Parcela'=>$calc['parcela'],
                'Data da Operação'=>$calc['data_pagamento'],
                'Valor'=>$calc['total'],
                'Tipo de Movimento'=>'Entradas',
                'Descrição'=>'Receita de Juros',
                'usuario'=>mb_substr((string)$request->user()->name,0,255),
            ]);
            $unpaid=$db->table('Contractos')->where('Nr do Contracto',$numero)
                ->whereNotIn('Estado',['Pago','Pago em Atraso'])->count();
            if ($unpaid===0) {
                $db->table('Contractos')->where('Nr do Contracto',$numero)
                    ->update(['Estado do Contrato'=>'Finalizado']);
            }
            return $calc+['contrato_finalizado'=>$unpaid===0,'movimento_registado'=>true];
        });
        return response()->json($result,201)->header('Cache-Control','no-store, private');
    }
}
