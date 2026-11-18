<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CashAccountLedger;
use App\Services\ContractHistory;
use App\Services\LegacyContractPricing;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LIQUIDAÇÃO TOTAL de uma prestação (contratos CIS novos e históricos do VBA).
 *
 * Cobrança.frm (HandlePayment + UpdateTable6 + ActualizarFinanceiroCobranca "TOTAL"):
 *  - Pendente -> 'Pago'; atrasada -> 'Pago em Atraso'; Situação 'Pagamento Completo';
 *  - Entrada no Extracto 'Receita de Juros' com Contracto, Cliente e Parcela;
 *  - juro variável: Capital × %Juro/100 × dias (Data de Inicio -> pagamento), ROUND 2
 *    (Image8_Click); Capital<=0 usa Quantia Creditada (compatibilidade VBA).
 *  - multa opcional sobre os juros (Parcelas.frm, c_vrem = juros).
 *
 * Diferenças intencionais face ao VBA (documentadas em docs/cobrancas-caixa.md):
 *  - o VBA soma juros duas vezes na liquidação atrasada com multa
 *    (v_total = v_pagar + v_multa + v_juros); o CIS cobra Valor a Pagar + multa;
 *  - a entrada fica OBRIGATORIAMENTE numa conta activa da Caixa (account_id),
 *    com chave idempotente e fotografia antes/depois em cis_contractos_historico;
 *  - pagamentos parciais, renovação e capitalização continuam só em simulação.
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

    private static function isCisContract(object $row): bool
    {
        $s=$row->{'Estratégia de Cálculo'}??null;
        return $s!==null && trim((string)$s)!=='';
    }

    private static function date(mixed $value,string $label): CarbonImmutable
    {
        $s=substr(trim((string)$value),0,10);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/',$s)===1 && !str_starts_with($s,'0000'),409,
            $label.' inválida no registo legado; cobrança bloqueada até correcção.');
        return CarbonImmutable::createFromFormat('!Y-m-d',$s);
    }

    /** Cálculo único do valor devido, em cêntimos. */
    private function quote(object $row,string $date,bool $multa): array
    {
        $today=CarbonImmutable::createFromFormat('!Y-m-d',$date);
        $due=self::date($row->{'Data de Cobrança'},'Data de cobrança');
        $start=self::date($row->{'Data de Inicio'},'Data de início');
        $delay=max(0,(int)$due->diffInDays($today,false));
        $days=max(0,(int)$start->diffInDays($today,false));
        $variable=in_array((string)$row->{'Tipo de Juro'},['Variável','Variavel'],true);
        $rate=(float)$row->{'% de Juro'};
        $fineRate=(float)($row->{'% de Multa'}??0);
        $avisos=[];
        if (self::isCisContract($row)) {
            // Contratos CIS: meticais inteiros, arredondamento para cima (motor homologado).
            $interest=$variable
                ? LegacyContractPricing::upwards((int)$row->Capital*$rate/100*$days)
                : (int)$row->{'Valor de Juros'};
            $base=$variable?(int)$row->{'Amortização'}+$interest:(int)$row->{'Valor a Pagar'};
            $fine=$multa&&$delay>0?LegacyContractPricing::upwards($interest*$fineRate/100):0;
            [$interestC,$baseC,$fineC]=[$interest*100,$base*100,$fine*100];
            $origem='cis';
        } else {
            // Contratos históricos do VBA: valores com 2 casas, ROUND como no Excel/MySQL.
            $capitalC=CashAccountLedger::legacyCents($row->Capital);
            if ($capitalC<=0) {
                $capitalC=CashAccountLedger::legacyCents($row->{'Quantia Creditada'});
            }
            abort_unless($capitalC>0,409,'Prestação histórica sem capital; requer reconciliação.');
            if ($variable) {
                $interestC=(int)round($capitalC*$rate/100*$days);
                $baseC=$capitalC+$interestC;
                $avisos[]='Juro variável recalculado à data de hoje: capital × taxa × '.$days.' dia(s).';
            } else {
                $interestC=CashAccountLedger::legacyCents($row->{'Valor de Juros'});
                $baseC=CashAccountLedger::legacyCents($row->{'Valor a Pagar'});
            }
            $fineC=$multa&&$delay>0?(int)round($interestC*$fineRate/100):0;
            $prefilled=CashAccountLedger::legacyCents($row->{'Valor Pago'}??0);
            if ($prefilled>0 && $prefilled!==$baseC) {
                $avisos[]='O campo «Valor Pago» do VBA ('.CashAccountLedger::amount($prefilled)
                    .' MT) difere do valor a pagar; foi usado o valor a pagar.';
            }
            $origem='historico';
        }
        abort_unless($baseC>0,409,'Valor a pagar inválido nesta prestação.');
        $total=$baseC+$fineC;
        $avisos[]='Liquidação total. Multa opcional sobre os juros (Parcelas.frm).';
        return [
            'parcela'=>(int)$row->{'Nº da Parcela'},
            'origem'=>$origem,
            'dias_atraso'=>$delay,
            'juros'=>(float)CashAccountLedger::amount($interestC),
            'multa'=>(float)CashAccountLedger::amount($fineC),
            'total'=>(float)CashAccountLedger::amount($total),
            'total_texto'=>CashAccountLedger::amount($total),
            'estado'=>$delay>0?'Pago em Atraso':'Pago',
            'data_pagamento'=>$date,
            'aviso'=>implode(' ',$avisos),
            '_cents'=>['juros'=>$interestC,'multa'=>$fineC,'total'=>$total],
        ];
    }

    private function eligibility($db,object $row): void
    {
        abort_unless(in_array($row->Estado,['Pendente','Pagamento Atrasado'],true),409,
            'A prestação já não está pendente.');
        abort_if(in_array(trim((string)($row->{'Estado do Contrato'}??'')),['Finalizado','Cancelado','Anulado'],true)
            && self::isCisContract($row),409,'Contrato encerrado.');
        if (self::isCisContract($row)) {
            abort_unless((float)($row->{'Valor Pago'}??0)===0.0,409,
                'Existe valor pago no registo desta prestação; cobrança bloqueada.');
        }
        abort_if($db->table('Extracto')->where('Contracto',$row->{'Nr do Contracto'})
            ->where('Parcela',(string)$row->{'Nº da Parcela'})
            ->where('Tipo de Movimento','Entradas')->exists(),409,
            'Já existe uma entrada no Extracto para esta parcela; requer reconciliação antes de cobrar.');
    }

    public function preview(Request $request, string $numero, int $ordem)
    {
        $this->admin($request);
        $data=$request->validate([
            'data_pagamento'=>['required','date_format:Y-m-d'],
            'cobrar_multa'=>['required','boolean'],
        ]);
        abort_if($data['data_pagamento']!==now('Africa/Maputo')->toDateString(),422,
            'A data de pagamento real tem de ser hoje em Moçambique.');
        $db=DB::connection('mysql_legacy');
        $row=$db->table('Contractos')->where('Nr do Contracto',$numero)->where('Ordem',$ordem)->first();
        abort_unless($row,404,'Prestação não encontrada.');
        $this->eligibility($db,$row);
        $q=$this->quote($row,$data['data_pagamento'],(bool)$data['cobrar_multa']);
        unset($q['_cents']);
        $q['registo_activo']=CashAccountLedger::flag('CIS_COLLECTION_WRITES_APPROVED')
            && CashAccountLedger::flag('CIS_CASH_WRITES_APPROVED')
            && CashAccountLedger::flag(CashAccountLedger::FLAG);
        return response()->json($q)->header('Cache-Control','no-store, private');
    }

    public function collect(Request $request, string $numero, int $ordem)
    {
        $this->admin($request);
        abort_unless(CashAccountLedger::flag('CIS_COLLECTION_WRITES_APPROVED'),503,
            'A cobrança real ainda não foi activada no servidor.');
        CashAccountLedger::requireReady();
        $data=$request->validate([
            'data_pagamento'=>['required','date_format:Y-m-d'],
            'cobrar_multa'=>['required','boolean'],
            'valor_recebido'=>['required','string'],
            'conta_id'=>['required','integer','min:1'],
            'referencia'=>['required','string','min:4','max:60'],
            'idempotency_key'=>['required','uuid'],
            'confirmacao_recebimento'=>['required','accepted'],
        ]);
        abort_if($data['data_pagamento']!==now('Africa/Maputo')->toDateString(),422,
            'A cobrança efectiva deve ser registada com a data de hoje.');
        $received=CashAccountLedger::cents($data['valor_recebido'],'valor_recebido');
        $key='contract:collect:'.strtolower($data['idempotency_key']);
        $user=$request->user();
        $ref=trim(preg_replace('/\s+/',' ',$data['referencia']));
        $db=DB::connection('mysql_contract_writer');
        try {
        $result=$db->transaction(function () use ($db,$data,$numero,$ordem,$user,$received,$key,$ref) {
            // Ordem de lock: conta -> prestação (igual em desembolso; evita deadlocks).
            [$account,$before]=CashAccountLedger::lockActive($db,(int)$data['conta_id']);
            $existing=$db->table('Extracto')->where('operation_key',$key)->first();
            if ($existing) {
                abort_unless((string)$existing->Contracto===$numero
                    && (int)$existing->account_id===(int)$account->id,409,
                    'A chave da operação já foi usada para outro pagamento.');
                return ['registado'=>true,'repetido'=>true,'extracto_nr'=>(int)$existing->Nr,
                    'valor'=>CashAccountLedger::amount(CashAccountLedger::cents((string)$existing->Valor)),
                    'notice'=>'Pagamento já registado anteriormente; nada foi duplicado.'];
            }
            $rows=$db->table('Contractos')->where('Nr do Contracto',$numero)
                ->where('Ordem',$ordem)->lockForUpdate()->get();
            abort_unless($rows->count()===1,409,'Prestação não encontrada ou identificador duplicado.');
            $row=$rows[0];
            $this->eligibility($db,$row);
            $calc=$this->quote($row,$data['data_pagamento'],(bool)$data['cobrar_multa']);
            $c=$calc['_cents'];
            if ($c['total']!==$received) {
                throw ValidationException::withMessages(['valor_recebido'=>
                    'O valor recebido não corresponde ao valor devido calculado no servidor ('
                    .$calc['total_texto'].' MT).']);
            }
            $updated=$db->table('Contractos')->where('Ordem',$ordem)
                ->where('Nr do Contracto',$numero)
                ->whereIn('Estado',['Pendente','Pagamento Atrasado'])
                ->update([
                    'Valor de Juros'=>CashAccountLedger::amount($c['juros']),
                    'Valor de Multa'=>CashAccountLedger::amount($c['multa']),
                    'Valor a Pagar'=>CashAccountLedger::amount($c['total']),
                    'Valor Pago'=>CashAccountLedger::amount($c['total']),
                    'Data de Pagamento'=>$calc['data_pagamento'],
                    'Estado'=>$calc['estado'],
                    'Dias'=>$calc['dias_atraso'],
                    'Situação de Pagamento'=>'Pagamento Completo',
                ]);
            abort_unless($updated===1,409,'A prestação foi alterada por outra operação.');
            $nr=$db->table('Extracto')->insertGetId([
                'Contracto'=>$numero,
                'Cliente'=>mb_substr((string)$row->{'Nome do Cliente'},0,255),
                'Parcela'=>(string)$calc['parcela'],
                'Data da Operação'=>$calc['data_pagamento'],
                'Valor'=>CashAccountLedger::amount($c['total']),
                'Tipo de Movimento'=>'Entradas',
                'Descrição'=>mb_substr('[CAIXA:COBRANCA] Receita de Juros '.$numero.' P'.$calc['parcela'].' | REF:'.$ref,0,255),
                'usuario'=>mb_substr((string)$user->name,0,255),
                'account_id'=>(int)$account->id,
                'operation_key'=>$key,
            ],'Nr');
            $after=$db->table('Contractos')->where('Ordem',$ordem)->first();
            ContractHistory::record($db,[
                'contract_number'=>$numero,'installment_ordem'=>$ordem,
                'installment_number'=>$calc['parcela'],'operation'=>'cobranca_total',
                'before'=>$row,'after'=>$after,'extracto_nr'=>$nr,'account_id'=>(int)$account->id,
                'amount'=>CashAccountLedger::amount($c['total']),'evidence_reference'=>$ref,
                'reason'=>$calc['origem']==='historico'?'Liquidação total de prestação histórica (VBA).':'Liquidação total.',
                'operation_key'=>$key,'performed_by'=>(int)$user->id,
            ]);
            $unpaid=$db->table('Contractos')->where('Nr do Contracto',$numero)
                ->whereNotIn('Estado',['Pago','Pago em Atraso'])->count();
            if ($unpaid===0) {
                $db->table('Contractos')->where('Nr do Contracto',$numero)
                    ->update(['Estado do Contrato'=>'Finalizado']);
            }
            unset($calc['_cents']);
            return $calc+['registado'=>true,'repetido'=>false,'extracto_nr'=>(int)$nr,
                'conta'=>$account->code,'saldo_conta_anterior'=>CashAccountLedger::amount($before),
                'saldo_conta_actual'=>CashAccountLedger::amount($before+$c['total']),
                'contrato_finalizado'=>$unpaid===0,
                'notice'=>'Pagamento registado na conta '.$account->code.' com histórico antes/depois.'];
        },3);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            abort(503,'A base de dados recusou a operação (MySQL '.($e->errorInfo[1]??'?').'). Nada foi gravado. '
                .'Verifique as permissões com: bash deploy/verify-contract-cash-flows.sh');
        }
        return response()->json($result,empty($result['repetido'])?201:200)
            ->header('Cache-Control','no-store, private');
    }

    /** Histórico imutável da prestação/contrato (leitura). */
    public function history(Request $request, string $numero)
    {
        $this->admin($request);
        try {
            $db=DB::connection('mysql_legacy');
            $rows=$db->getSchemaBuilder()->hasTable('cis_contractos_historico')
                ? $db->table('cis_contractos_historico')->where('contract_number',$numero)
                    ->orderByDesc('id')->limit(200)->get()
                : null;
        } catch (\Throwable $e) {
            $rows=null; // sem permissão de leitura ainda: não expor detalhes SQL
        }
        if ($rows===null) {
            return response()->json(['data'=>[],'instalado'=>false])->header('Cache-Control','no-store, private');
        }
        return response()->json(['instalado'=>true,'data'=>$rows->map(fn($r)=>[
            'id'=>(int)$r->id,'operacao'=>$r->operation,'parcela'=>$r->installment_number,
            'ordem'=>$r->installment_ordem,'valor'=>$r->amount,'conta_id'=>$r->account_id,
            'extracto_nr'=>$r->extracto_nr,'referencia'=>$r->evidence_reference,'motivo'=>$r->reason,
            'antes'=>json_decode((string)$r->before_snapshot,true),'depois'=>json_decode((string)$r->after_snapshot,true),
            'utilizador'=>(int)$r->performed_by,'em'=>$r->performed_at,
        ])])->header('Cache-Control','no-store, private');
    }
}
