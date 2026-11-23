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
 *  - RENOVAÇÃO (mesmo contrato): juros (+multa opcional) pagos, prestação 'Renovado' e nova
 *    prestação com o mesmo capital; início escolhido, vencimento = início + mesmo prazo.
 *  - pagamento parcial, liquidação do saldo, renovação dos juros, capitalização e prorrogação 15→30
 *    activados (operate/previewOperation), com as mesmas garantias.
 */
final class ContractCollectionController extends Controller
{
    private function admin(Request $request): void
    {
        $u=$request->user();
        \App\Support\Permissions::require($u,'cobrancas');
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
        $avisos[]='Liquidação total. Juros de mora opcionais sobre os juros (Parcelas.frm).';
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

    /** Data efectiva do recebimento: hoje (todos) ou passada (só administrador), nunca futura. */
    private function paymentDate(object $row,string $date,?object $u): void
    {
        $today=now('Africa/Maputo')->toDateString();
        abort_if($date>$today,422,'A data de pagamento não pode ser futura.');
        // Só o administrador pode registar um recebimento com data diferente de hoje.
        abort_if($date!==$today && !\App\Support\Permissions::can($u,'cobrancas.data'),403,
            'Sem permissão para registar pagamentos com data passada; registe com a data de hoje ou peça ao gestor.');
        // Pagamento antecipado (antes do início, p. ex. após renovação) é permitido.
    }

    /** A data tem de estar dentro do período aberto da conta: após a abertura e sem fecho diário nesse dia ou depois. */
    private function cashDateOpen(object $account,string $date): void
    {
        $cut=substr((string)($account->cut_at??''),0,10);
        abort_if($cut!=='' && $date<$cut,422,'A data de pagamento é anterior à abertura da conta '.$account->code
            .' na Caixa ('.CarbonImmutable::parse($cut)->format('d/m/Y').').');
        try {
            $closed=DB::connection()->table('cis_cash_daily_closures')->where('account_id',(int)$account->id)
                ->where('closing_date','>=',$date)->max('closing_date');
        } catch (\Illuminate\Database\QueryException $e) {
            if ((int)($e->errorInfo[1]??0)===1146) return; // fechos diários ainda não instalados
            throw $e;
        }
        abort_if($closed!==null,422,'A conta '.$account->code.' já tem fecho diário em '
            .CarbonImmutable::parse($closed)->format('d/m/Y').'; não é possível lançar pagamentos com data até esse dia.');
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
            'Já existe uma entrada no Extracto para esta prestação; requer reconciliação antes de cobrar.');
    }

    public function preview(Request $request, string $numero, int $ordem)
    {
        $this->admin($request);
        $data=$request->validate([
            'data_pagamento'=>['required','date_format:Y-m-d'],
            'cobrar_multa'=>['required','boolean'],
        ]);

        $db=DB::connection('mysql_legacy');
        $row=$db->table('Contractos')->where('Nr do Contracto',$numero)->where('Ordem',$ordem)->first();
        abort_unless($row,404,'Prestação não encontrada.');
        $this->eligibility($db,$row);
        $this->paymentDate($row,$data['data_pagamento'],$request->user());
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
            $this->paymentDate($row,$data['data_pagamento'],$user);
            $this->cashDateOpen($account,$data['data_pagamento']);
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
                ->whereNotIn('Estado',['Pago','Pago em Atraso','Renovado'])->count();
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

    /** Prazo de uma prestação: «Prazo/Duração» (uma parcela) ou intervalo para a prestação anterior. */
    private function renewalTerm($db,object $row): array
    {
        $parcels=(int)($row->{'Nº de Prestações'}??1);
        $prazo=trim((string)($row->{'Prazo/Duração'}??''));
        if ($parcels<=1 && preg_match('/^(\d{1,3})\s*(semana|semanas|m[eê]s|meses|dia|dias)$/iu',$prazo,$m)) {
            $n=(int)$m[1];$u=mb_strtolower($m[2]);
            if (str_starts_with($u,'semana')) return ['days',$n*7,$prazo];
            if (str_starts_with($u,'dia')) return ['days',$n,$prazo];
            return ['months',$n,$prazo];
        }
        $due=self::date($row->{'Data de Cobrança'},'Data de cobrança');
        $prevDue=$db->table('Contractos')->where('Nr do Contracto',$row->{'Nr do Contracto'})
            ->where('Data de Cobrança','<',$due->toDateString())->max('Data de Cobrança');
        $from=$prevDue?self::date($prevDue,'Data de cobrança anterior'):self::date($row->{'Data de Inicio'},'Data de início');
        $days=(int)$from->diffInDays($due,false);
        abort_unless($days>=1 && $days<=366,409,'Não foi possível determinar o prazo da prestação para renovar.');
        return ['days',$days,$days.' dia(s)'];
    }

    /** Cálculo da renovação: juros (+ multa opcional) agora, nova prestação com o mesmo capital. */
    private function renewalPlan($db,object $row,string $date,bool $multa,string $start): array
    {
        abort_unless(in_array($row->Estado,['Pendente','Pagamento Atrasado'],true),409,'A prestação já não está pendente.');
        $open=$db->table('Contractos')->where('Nr do Contracto',$row->{'Nr do Contracto'})
            ->whereIn('Estado',['Pendente','Pagamento Atrasado'])->count();
        abort_unless($open===1,409,'Só é possível renovar quando esta é a única prestação em curso do contrato.');
        $q=$this->quote($row,$date,$multa);
        $c=$q['_cents'];
        abort_unless($c['juros']>0,409,'Prestação sem juros a cobrar; renovação não aplicável.');
        $principalC=$c['total']-$c['multa']-$c['juros'];
        abort_unless($principalC>0,409,'Prestação sem capital em dívida; use o pagamento integral.');
        $today=CarbonImmutable::createFromFormat('!Y-m-d',$date);
        $oldStart=self::date($row->{'Data de Inicio'},'Data de início');
        $oldDue=self::date($row->{'Data de Cobrança'},'Data de cobrança');
        $startD=CarbonImmutable::createFromFormat('!Y-m-d',$start);
        abort_unless($startD->greaterThan($oldStart),422,'A data de início da renovação tem de ser posterior ao início actual ('.$oldStart->format('d/m/Y').').');
        abort_unless($startD->lessThanOrEqualTo($today->addDays(60)),422,'A data de início da renovação não pode passar de 60 dias a partir de hoje.');
        [$unit,$n,$label]=$this->renewalTerm($db,$row);
        $newDue=$unit==='months'?$startD->addMonthsNoOverflow($n):$startD->addDays($n);
        abort_unless($newDue->greaterThan($today),422,'O novo vencimento ('.$newDue->format('d/m/Y').') não é posterior à data de pagamento; escolha uma data de início mais recente.');
        $variable=in_array((string)$row->{'Tipo de Juro'},['Variável','Variavel'],true);
        if ($variable) {
            $termDays=(int)$startD->diffInDays($newDue);
            $newInterestC=self::isCisContract($row)
                ? LegacyContractPricing::upwards($principalC/100*(float)$row->{'% de Juro'}/100*$termDays)*100
                : (int)round($principalC*(float)$row->{'% de Juro'}/100*$termDays);
        } else {
            $newInterestC=self::isCisContract($row)?(int)$row->{'Valor de Juros'}*100:CashAccountLedger::legacyCents($row->{'Valor de Juros'});
        }
        $avisos=[];
        if (!$startD->equalTo($oldDue)) {
            $avisos[]='A data de início escolhida difere do vencimento anterior ('.$oldDue->format('d/m/Y').').';
        }
        if ($variable) $avisos[]='Juro variável: o valor da nova prestação é estimado e será recalculado no pagamento.';
        $paidC=$c['juros']+$c['multa'];
        $max=(int)$db->table('Contractos')->where('Nr do Contracto',$row->{'Nr do Contracto'})->max('Nº da Parcela');
        return [
            'parcela'=>$q['parcela'],'origem'=>$q['origem'],'dias_atraso'=>$q['dias_atraso'],
            'juros'=>(float)CashAccountLedger::amount($c['juros']),'multa'=>(float)CashAccountLedger::amount($c['multa']),
            'total'=>(float)CashAccountLedger::amount($paidC),'total_texto'=>CashAccountLedger::amount($paidC),
            'data_pagamento'=>$date,
            'nova_prestacao'=>[
                'parcela'=>$max+1,'data_inicio'=>$startD->toDateString(),'data_vencimento'=>$newDue->toDateString(),
                'prazo'=>$label,'capital'=>CashAccountLedger::amount($principalC),
                'juros'=>CashAccountLedger::amount($newInterestC),
                'valor_a_pagar'=>CashAccountLedger::amount($principalC+$newInterestC),
                'juros_estimado'=>$variable,
            ],
            'aviso'=>implode(' ',$avisos),
            '_cents'=>['juros'=>$c['juros'],'multa'=>$c['multa'],'total'=>$paidC,
                'principal'=>$principalC,'novo_juros'=>$newInterestC],
        ];
    }

    public function previewRenewal(Request $request, string $numero, int $ordem)
    {
        $this->admin($request);
        $data=$request->validate([
            'data_pagamento'=>['required','date_format:Y-m-d'],
            'cobrar_multa'=>['required','boolean'],
            'data_inicio'=>['required','date_format:Y-m-d'],
        ]);

        $db=DB::connection('mysql_legacy');
        $row=$db->table('Contractos')->where('Nr do Contracto',$numero)->where('Ordem',$ordem)->first();
        abort_unless($row,404,'Prestação não encontrada.');
        $this->eligibility($db,$row);
        $this->paymentDate($row,$data['data_pagamento'],$request->user());
        $q=$this->renewalPlan($db,$row,$data['data_pagamento'],(bool)$data['cobrar_multa'],$data['data_inicio']);
        unset($q['_cents']);
        $q['registo_activo']=CashAccountLedger::flag('CIS_COLLECTION_WRITES_APPROVED')
            && CashAccountLedger::flag('CIS_CASH_WRITES_APPROVED')
            && CashAccountLedger::flag(CashAccountLedger::FLAG);
        return response()->json($q)->header('Cache-Control','no-store, private');
    }

    public function renew(Request $request, string $numero, int $ordem)
    {
        $this->admin($request);
        abort_unless(CashAccountLedger::flag('CIS_COLLECTION_WRITES_APPROVED'),503,
            'A cobrança real ainda não foi activada no servidor.');
        CashAccountLedger::requireReady();
        $data=$request->validate([
            'data_pagamento'=>['required','date_format:Y-m-d'],
            'cobrar_multa'=>['required','boolean'],
            'data_inicio'=>['required','date_format:Y-m-d'],
            'valor_recebido'=>['required','string'],
            'conta_id'=>['required','integer','min:1'],
            'referencia'=>['required','string','min:4','max:60'],
            'idempotency_key'=>['required','uuid'],
            'confirmacao_recebimento'=>['required','accepted'],
        ]);

        $received=CashAccountLedger::cents($data['valor_recebido'],'valor_recebido');
        $key='contract:renew:'.strtolower($data['idempotency_key']);
        $user=$request->user();
        $ref=trim(preg_replace('/\s+/',' ',$data['referencia']));
        $db=DB::connection('mysql_contract_writer');
        try {
        $result=$db->transaction(function () use ($db,$data,$numero,$ordem,$user,$received,$key,$ref) {
            [$account,$before]=CashAccountLedger::lockActive($db,(int)$data['conta_id']);
            $existing=$db->table('Extracto')->where('operation_key',$key)->first();
            if ($existing) {
                abort_unless((string)$existing->Contracto===$numero
                    && (int)$existing->account_id===(int)$account->id,409,
                    'A chave da operação já foi usada para outra operação.');
                return ['registado'=>true,'repetido'=>true,'extracto_nr'=>(int)$existing->Nr,
                    'valor'=>CashAccountLedger::amount(CashAccountLedger::cents((string)$existing->Valor)),
                    'notice'=>'Renovação já registada anteriormente; nada foi duplicado.'];
            }
            $all=$db->table('Contractos')->where('Nr do Contracto',$numero)->lockForUpdate()->get();
            $row=$all->firstWhere('Ordem',$ordem);
            abort_unless($row && $all->where('Ordem',$ordem)->count()===1,409,'Prestação não encontrada ou identificador duplicado.');
            $this->eligibility($db,$row);
            $this->paymentDate($row,$data['data_pagamento'],$user);
            $this->cashDateOpen($account,$data['data_pagamento']);
            $plan=$this->renewalPlan($db,$row,$data['data_pagamento'],(bool)$data['cobrar_multa'],$data['data_inicio']);
            $c=$plan['_cents'];$np=$plan['nova_prestacao'];
            if ($c['total']!==$received) {
                throw ValidationException::withMessages(['valor_recebido'=>
                    'O valor recebido não corresponde aos juros calculados no servidor ('.$plan['total_texto'].' MT).']);
            }
            $updated=$db->table('Contractos')->where('Ordem',$ordem)->where('Nr do Contracto',$numero)
                ->whereIn('Estado',['Pendente','Pagamento Atrasado'])
                ->update([
                    'Valor de Juros'=>CashAccountLedger::amount($c['juros']),
                    'Valor de Multa'=>CashAccountLedger::amount($c['multa']),
                    'Valor Pago'=>CashAccountLedger::amount($c['total']),
                    'Data de Pagamento'=>$plan['data_pagamento'],
                    'Estado'=>'Renovado',
                    'Dias'=>$plan['dias_atraso'],
                    'Situação de Pagamento'=>'Juros pagos - renovado',
                ]);
            abort_unless($updated===1,409,'A prestação foi alterada por outra operação.');
            // Nova prestação no MESMO contrato: copia condições, muda só datas, número e valores.
            $new=(array)$row;
            unset($new['Ordem']);
            $capitalInt=(int)round($c['principal']/100);
            $new=array_merge($new,[
                'Nº da Parcela'=>$np['parcela'],
                'Nº de Prestações'=>(int)($row->{'Nº de Prestações'}??1)+1,
                'Data de Inicio'=>$np['data_inicio'],
                'Data de Cobrança'=>$np['data_vencimento'],
                'Data Final'=>$np['data_vencimento'],
                'Capital'=>$capitalInt,
                'Amortização'=>$capitalInt,
                'Valor de Juros'=>CashAccountLedger::amount($c['novo_juros']),
                'Valor a Pagar'=>CashAccountLedger::amount($c['principal']+$c['novo_juros']),
                'Valor Pago'=>0,'Valor de Multa'=>0,
                'Data de Pagamento'=>null,
                'Estado'=>'Pendente',
                'Estado do Contrato'=>'Em Progresso',
                'Situação de Pagamento'=>'Em Progresso',
                'Dias'=>0,
                'usuario'=>mb_substr((string)$user->name,0,255),
            ]);
            [$sql,$bindings]=\App\Services\LegacyContractInsert::statement($new);
            $db->insert($sql,$bindings);
            $newOrdem=(int)$db->getPdo()->lastInsertId();
            $nr=$db->table('Extracto')->insertGetId([
                'Contracto'=>$numero,
                'Cliente'=>mb_substr((string)$row->{'Nome do Cliente'},0,255),
                'Parcela'=>(string)$plan['parcela'],
                'Data da Operação'=>$plan['data_pagamento'],
                'Valor'=>CashAccountLedger::amount($c['total']),
                'Tipo de Movimento'=>'Entradas',
                'Descrição'=>mb_substr('[CAIXA:COBRANCA] Juros de renovação '.$numero.' P'.$plan['parcela'].' | REF:'.$ref,0,255),
                'usuario'=>mb_substr((string)$user->name,0,255),
                'account_id'=>(int)$account->id,
                'operation_key'=>$key,
            ],'Nr');
            $after=$db->table('Contractos')->where('Ordem',$ordem)->first();
            $newRow=$db->table('Contractos')->where('Ordem',$newOrdem)->first();
            abort_unless($newRow && (string)$newRow->{'Nr do Contracto'}===$numero,409,'Falha ao criar a nova prestação.');
            ContractHistory::record($db,[
                'contract_number'=>$numero,'installment_ordem'=>$ordem,
                'installment_number'=>$plan['parcela'],'operation'=>'renovar_juros',
                'before'=>$row,'after'=>$after,'extracto_nr'=>$nr,'account_id'=>(int)$account->id,
                'amount'=>CashAccountLedger::amount($c['total']),'evidence_reference'=>$ref,
                'reason'=>'Renovação: juros pagos'.($c['multa']>0?' com juros de mora':'').'; novo período de '.$np['prazo'].'.',
                'operation_key'=>$key,'performed_by'=>(int)$user->id,
            ]);
            ContractHistory::record($db,[
                'contract_number'=>$numero,'installment_ordem'=>$newOrdem,
                'installment_number'=>$np['parcela'],'operation'=>'renovacao_nova_prestacao',
                'before'=>null,'after'=>$newRow,'extracto_nr'=>$nr,'account_id'=>(int)$account->id,
                'amount'=>CashAccountLedger::amount($c['principal']+$c['novo_juros']),'evidence_reference'=>$ref,
                'reason'=>'Nova prestação criada pela renovação da P'.$plan['parcela'].'.',
                'operation_key'=>$key,'performed_by'=>(int)$user->id,
            ]);
            unset($plan['_cents']);
            return $plan+['registado'=>true,'repetido'=>false,'extracto_nr'=>(int)$nr,'nova_ordem'=>$newOrdem,
                'conta'=>$account->code,'saldo_conta_anterior'=>CashAccountLedger::amount($before),
                'saldo_conta_actual'=>CashAccountLedger::amount($before+$c['total']),
                'notice'=>'Renovação registada: juros na conta '.$account->code.'; nova prestação P'.$np['parcela']
                    .' vence a '.CarbonImmutable::parse($np['data_vencimento'])->format('d/m/Y').'.'];
        },3);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            abort(503,'A base de dados recusou a operação (MySQL '.($e->errorInfo[1]??'?').'). Nada foi gravado. '
                .'Verifique as permissões com: bash deploy/verify-contract-cash-flows.sh');
        }
        return response()->json($result,empty($result['repetido'])?201:200)
            ->header('Cache-Control','no-store, private');
    }

    // ===================== Operações de cobrança activadas =====================
    // Pagamento parcial e liquidação do saldo (com Caixa); renovação dos juros, capitalização e
    // prorrogação 15→30 dias (sem recebimento). Todas com chave idempotente e histórico antes/depois.

    public const OPERATIONS=['parcial','remanescente','renovar_juros','capitalizar','prorrogar_15_30'];
    private const CASH_OPS=['parcial','remanescente'];

    /** Valor já recebido em parciais desta prestação (entradas no Extracto), ou null se não reconciliado. */
    private function partialPaid($db,object $row): int
    {
        $sum=0;
        foreach ($db->table('Extracto')->where('Contracto',$row->{'Nr do Contracto'})
            ->where('Parcela',(string)$row->{'Nº da Parcela'})->where('Tipo de Movimento','Entradas')->pluck('Valor') as $v) {
            $sum+=CashAccountLedger::legacyCents($v);
        }
        $isPartial=str_contains(mb_strtolower((string)($row->{'Situação de Pagamento'}??'')),'parcial');
        if (!$isPartial) {
            abort_if($sum>0,409,'Já existe uma entrada no Extracto para esta prestação; requer reconciliação.');
            return 0;
        }
        abort_unless($sum===CashAccountLedger::legacyCents($row->{'Valor Pago'}??0),409,
            'Os pagamentos parciais registados não correspondem ao Extracto; requer reconciliação.');
        return $sum;
    }

    private function opBase($db,object $row): void
    {
        abort_unless(in_array($row->Estado,['Pendente','Pagamento Atrasado'],true),409,'A prestação já não está em curso.');
        abort_if(in_array(trim((string)($row->{'Estado do Contrato'}??'')),['Finalizado','Cancelado','Anulado'],true),409,'Contrato encerrado.');
    }

    /** Valor monetário no formato da linha: inteiros nos contratos CIS, 2 casas nos históricos. */
    private static function money(object $row,int $cents)
    {
        return self::isCisContract($row)?(int)round($cents/100):CashAccountLedger::amount($cents);
    }

    private static function stateFor(CarbonImmutable $due): string
    {
        return $due->lessThan(CarbonImmutable::now('Africa/Maputo')->startOfDay())?'Pagamento Atrasado':'Pendente';
    }

    /** Plano de cada operação (usado na prévia e na gravação). */
    private function operationPlan($db,object $row,array $d,?object $user): array
    {
        $op=$d['operacao'];
        $this->opBase($db,$row);
        $already=$this->partialPaid($db,$row);
        $isCis=self::isCisContract($row);
        $C=fn($v)=>$isCis?(int)round((float)$v)*100:CashAccountLedger::legacyCents($v);
        $interestC=$C($row->{'Valor de Juros'}??0);
        $dueC=$C($row->{'Valor a Pagar'}??0);
        $fineC=$C($row->{'Valor de Multa'}??0);
        $principalC=$dueC-$interestC-$fineC;
        if ($op==='parcial'||$op==='remanescente') {
            $this->paymentDate($row,$d['data_pagamento'],$user);
            $q=$this->quote($row,$d['data_pagamento'],$op==='remanescente'&&!empty($d['cobrar_multa']));
            $c=$q['_cents'];
            if ($op==='parcial') {
                // Pagamento parcial = paga os juros da prestação e parte do capital; o CAPITAL QUE FICA
                // + juros (taxa da tabela para esse capital, editável) passa a ser uma NOVA PRESTAÇÃO
                // no mesmo contrato, com início escolhido e vencimento = início + mesmo prazo.
                abort_unless($already===0,409,'Esta prestação tem pagamentos parciais antigos; liquide o saldo.');
                $juros=$c['juros'];$baseC=$c['total'];
                $raw=trim((string)($d['valor_recebido']??''));
                $paid=$raw===''?null:CashAccountLedger::cents($raw,'valor_recebido');
                if ($paid!==null) {
                    if ($isCis&&$paid%100!==0) throw ValidationException::withMessages(['valor_recebido'=>'Nos contratos do CIS os valores são em meticais inteiros.']);
                    if ($paid<$juros||$paid>=$baseC) {
                        throw ValidationException::withMessages(['valor_recebido'=>
                            'O pagamento parcial tem de cobrir pelo menos os juros ('.CashAccountLedger::amount($juros).' MT) e ser inferior ao valor da prestação ('
                            .CashAccountLedger::amount($baseC).' MT). Para pagar tudo use a liquidação.']);
                    }
                }
                $p=$paid??$juros;
                $remainingC=$baseC-$p; // capital que fica em dívida
                $term=mb_strtolower(trim((string)($row->{'Prazo/Duração'}??'')));
                $period=preg_match('/^(?:15\s*dias?|2\s*semanas)$/u',$term)?15:30;
                $tableRate=null;
                try {
                    $m=DB::table('cis_tabela_juros')->where('limite_inferior','<=',CashAccountLedger::amount($remainingC))
                        ->where('limite_superior','>=',CashAccountLedger::amount($remainingC))->where('periodo_referencia',$period)->get(['taxa']);
                    if ($m->count()===1) $tableRate=(float)$m[0]->taxa;
                } catch (\Throwable) {}
                $informed=isset($d['taxa_informada'])&&$d['taxa_informada']!==null&&$d['taxa_informada']!==''?round((float)$d['taxa_informada'],4):null;
                $rate=$informed??$tableRate??(float)($row->{'% de Juro'}??0);
                $altered=$informed!==null&&($tableRate===null||abs($informed-$tableRate)>0.00001);
                $newInterestC=$isCis?LegacyContractPricing::upwards($remainingC/100*$rate/100)*100:(int)round($remainingC*$rate/100);
                $oldDue=self::date($row->{'Data de Cobrança'},'Data de cobrança');
                // Sem data indicada: vencimento actual ou, se já passou, a data do pagamento.
                $payD=CarbonImmutable::createFromFormat('!Y-m-d',$d['data_pagamento']);
                $start=!empty($d['data_inicio'])?CarbonImmutable::createFromFormat('!Y-m-d',$d['data_inicio']):($oldDue->greaterThanOrEqualTo($payD)?$oldDue:$payD);
                $oldStart=self::date($row->{'Data de Inicio'},'Data de início');
                abort_unless($start->greaterThan($oldStart),422,'O início da nova prestação tem de ser posterior ao início actual ('.$oldStart->format('d/m/Y').').');
                [$unit,$n,$label]=$this->renewalTerm($db,$row);
                $newDue=$unit==='months'?$start->addMonthsNoOverflow($n):$start->addDays($n);
                $max=(int)$db->table('Contractos')->where('Nr do Contracto',$row->{'Nr do Contracto'})->max('Nº da Parcela');
                $fmtR=fn($r)=>$r===null?null:rtrim(rtrim(number_format($r,4,'.',''),'0'),'.');
                return ['operacao'=>$op,'parcela'=>$q['parcela'],'data_pagamento'=>$d['data_pagamento'],'dias_atraso'=>$q['dias_atraso'],
                    'valor'=>$paid===null?null:CashAccountLedger::amount($paid),'juros_cobertos'=>CashAccountLedger::amount($juros),
                    'amortizacao'=>$paid===null?null:CashAccountLedger::amount($p-$juros),'valor_prestacao'=>CashAccountLedger::amount($baseC),
                    'estado'=>$q['estado'],
                    'nova_prestacao'=>['parcela'=>$max+1,'capital'=>CashAccountLedger::amount($remainingC),'taxa'=>$fmtR($rate),
                        'juros'=>CashAccountLedger::amount($newInterestC),'valor_a_pagar'=>CashAccountLedger::amount($remainingC+$newInterestC),
                        'data_inicio'=>$start->toDateString(),'data_vencimento'=>$newDue->toDateString(),'prazo'=>$label],
                    'taxa_editavel'=>true,'taxa_tabela'=>$fmtR($tableRate),'taxa'=>$fmtR($rate),'taxa_alterada'=>$altered,
                    'proximo_valor'=>CashAccountLedger::amount($remainingC+$newInterestC),'proximo_vencimento'=>$newDue->toDateString(),
                    'aviso'=>'Juros primeiro, depois capital. O capital que fica ('.CashAccountLedger::amount($remainingC).' MT) mais os juros ('
                        .$fmtR($rate).'%'.($altered?', taxa indicada':', taxa da tabela').') passa a ser a nova prestação P'.($max+1).'.',
                    '_cents'=>['valor'=>$paid,'juros'=>$juros,'restante'=>$remainingC,'novo_juros'=>$newInterestC],'_rate'=>$rate];
            }
            abort_unless($already>0,409,'Esta prestação não tem pagamentos parciais; use a liquidação da prestação.');
            $remaining=$c['total']-$already;
            abort_unless($remaining>0,409,'Não há saldo em dívida nesta prestação.');
            return ['operacao'=>$op,'parcela'=>$q['parcela'],'data_pagamento'=>$d['data_pagamento'],'dias_atraso'=>$q['dias_atraso'],
                'valor'=>CashAccountLedger::amount($remaining),'ja_pago'=>CashAccountLedger::amount($already),
                'juros'=>CashAccountLedger::amount($c['juros']),'juros_mora'=>CashAccountLedger::amount($c['multa']),
                'valor_prestacao'=>CashAccountLedger::amount($c['total']),'estado'=>$q['estado'],
                'aviso'=>'Liquida o saldo da prestação depois dos pagamentos parciais'.($c['multa']>0?', com juros de mora':'').'.',
                '_cents'=>['valor'=>$remaining,'juros'=>$c['juros'],'multa'=>$c['multa'],'total'=>$c['total']]];
        }
        abort_unless($already===0,409,'A prestação tem pagamentos parciais; liquide o saldo antes de alterar condições.');
        abort_unless($principalC>0,409,'Prestação sem capital em dívida identificável.');
        $due=self::date($row->{'Data de Cobrança'},'Data de cobrança');
        $rate=(float)($row->{'% de Juro'}??0);
        if ($op==='renovar_juros') {
            abort_if(in_array((string)($row->{'Tipo de Juro'}??''),['Variável','Variavel'],true),409,
                'Juro variável: a renovação dos juros sem pagamento não se aplica.');
            abort_unless($rate>0,409,'Prestação sem taxa de juro.');
            [$unit,$n,$label]=$this->renewalTerm($db,$row);
            $newDue=$unit==='months'?$due->addMonthsNoOverflow($n):$due->addDays($n);
            $periodC=$isCis?LegacyContractPricing::upwards($principalC/100*$rate/100)*100:(int)round($principalC*$rate/100);
            return ['operacao'=>$op,'parcela'=>(int)$row->{'Nº da Parcela'},'capital'=>CashAccountLedger::amount($principalC),
                'juros_anteriores'=>CashAccountLedger::amount($interestC),'juros_periodo'=>CashAccountLedger::amount($periodC),
                'juros_novos'=>CashAccountLedger::amount($interestC+$periodC),'valor_a_pagar'=>CashAccountLedger::amount($dueC+$periodC),
                'vencimento_anterior'=>$due->toDateString(),'novo_vencimento'=>$newDue->toDateString(),'prazo'=>$label,
                'aviso'=>'Sem recebimento: acrescenta um período de juros ('.$rate.'%) e adia o vencimento por '.$label.'.',
                '_cents'=>['juros'=>$interestC+$periodC,'total'=>$dueC+$periodC],'_due'=>$newDue];
        }
        if ($op==='capitalizar') {
            $period=(int)($d['periodo_referencia']??0);
            abort_unless(in_array($period,[15,30],true),422,'Escolha o período de referência (15 ou 30 dias).');
            // Capital e Amortização são inteiros na tabela Contractos: o novo capital é arredondado ao metical.
            $newPrincipalC=(int)round(($principalC+$interestC)/100)*100;
            $m=DB::table('cis_tabela_juros')->where('limite_inferior','<=',CashAccountLedger::amount($newPrincipalC))
                ->where('limite_superior','>=',CashAccountLedger::amount($newPrincipalC))->where('periodo_referencia',$period)->get(['taxa']);
            $tableRate=$m->count()===1?(float)$m[0]->taxa:null;
            $informed=isset($d['taxa_informada'])&&$d['taxa_informada']!==null&&$d['taxa_informada']!==''?round((float)$d['taxa_informada'],4):null;
            $newRate=$informed??$tableRate;
            abort_unless($newRate!==null&&$newRate>0&&$newRate<=100,422,'Indique a taxa de juros a cobrar (sem faixa na tabela de preços).');
            $newInterestC=$isCis?LegacyContractPricing::upwards($newPrincipalC/100*$newRate/100)*100:(int)round($newPrincipalC*$newRate/100);
            $newDue=$due->addDays($period);
            return ['operacao'=>$op,'parcela'=>(int)$row->{'Nº da Parcela'},'capital_anterior'=>CashAccountLedger::amount($principalC),
                'juros_capitalizados'=>CashAccountLedger::amount($interestC),'capital_novo'=>CashAccountLedger::amount($newPrincipalC),
                'taxa_tabela'=>$tableRate,'taxa'=>$newRate,'taxa_alterada'=>$informed!==null&&($tableRate===null||abs($informed-$tableRate)>0.00001),
                'juros_novos'=>CashAccountLedger::amount($newInterestC),'valor_a_pagar'=>CashAccountLedger::amount($newPrincipalC+$newInterestC),
                'novo_inicio'=>$due->toDateString(),'novo_vencimento'=>$newDue->toDateString(),
                'aviso'=>'Sem recebimento: os juros em dívida passam a capital (arredondado ao metical) e é cobrado um novo período de juros.',
                '_cents'=>['capital_add'=>$newPrincipalC-$principalC,'principal'=>$newPrincipalC,'juros'=>$newInterestC,'total'=>$newPrincipalC+$newInterestC],
                '_due'=>$newDue,'_start'=>$due];
        }
        // prorrogar_15_30
        $terms=$db->table('Contractos')->where('Nr do Contracto',$row->{'Nr do Contracto'})->pluck('Prazo/Duração')
            ->map(fn($v)=>trim((string)$v))->filter()->unique()->values();
        abort_unless($terms->count()===1&&preg_match('/^(?:15\s*dias?|2\s*semanas)$/iu',$terms[0])===1,409,'Esta operação exige prazo actual de 15 dias.');
        $choice=$d['tratamento_juros']??null;
        abort_unless(in_array($choice,['manter','recalcular'],true),422,'Escolha o tratamento dos juros.');
        $start=self::date($row->{'Data de Inicio'},'Data de início');
        $newDue=$start->addDays(30);
        $newInterestC=$interestC;$newRate=$rate;
        if ($choice==='recalcular') {
            $m=DB::table('cis_tabela_juros')->where('limite_inferior','<=',CashAccountLedger::amount($principalC))
                ->where('limite_superior','>=',CashAccountLedger::amount($principalC))->where('periodo_referencia',30)->get(['taxa']);
            abort_unless($m->count()===1,409,'Não existe uma taxa única de 30 dias na tabela de preços para este capital.');
            $newRate=(float)$m[0]->taxa;
            $newInterestC=$isCis?LegacyContractPricing::upwards($principalC/100*$newRate/100)*100:(int)round($principalC*$newRate/100);
        }
        return ['operacao'=>$op,'parcela'=>(int)$row->{'Nº da Parcela'},'tratamento_juros'=>$choice,'vencimento_anterior'=>$due->toDateString(),
            'novo_vencimento'=>$newDue->toDateString(),'taxa'=>$newRate,'juros_novos'=>CashAccountLedger::amount($newInterestC),
            'valor_a_pagar'=>CashAccountLedger::amount($principalC+$fineC+$newInterestC),
            'aviso'=>'O contrato passa a 30 dias; vencimento = início + 30 dias'.($choice==='recalcular'?', juros à taxa de 30 dias.':', juros mantidos.'),
            '_cents'=>['juros'=>$newInterestC,'total'=>$principalC+$fineC+$newInterestC],'_due'=>$newDue];
    }

    private function operationRules(Request $request): array
    {
        if (is_string($request->input('taxa_informada'))) $request->merge(['taxa_informada'=>str_replace(',','.',trim($request->input('taxa_informada')))]);
        return $request->validate([
            'operacao'=>['required','in:'.implode(',',self::OPERATIONS)],
            'data_pagamento'=>['required_if:operacao,parcial,remanescente','nullable','date_format:Y-m-d'],
            'cobrar_multa'=>['nullable','boolean'],
            'valor_recebido'=>['nullable','string','max:20'],
            'periodo_referencia'=>['required_if:operacao,capitalizar','nullable','integer','in:15,30'],
            'taxa_informada'=>['nullable','numeric','gt:0','max:100','regex:/^\d{1,3}(?:\.\d{1,4})?$/'],
            'tratamento_juros'=>['required_if:operacao,prorrogar_15_30','nullable','in:manter,recalcular'],
            'data_inicio'=>['nullable','date_format:Y-m-d'],
        ]);
    }

    public function previewOperation(Request $request, string $numero, int $ordem)
    {
        $this->admin($request);
        $d=$this->operationRules($request);
        $db=DB::connection('mysql_legacy');
        $row=$db->table('Contractos')->where('Nr do Contracto',$numero)->where('Ordem',$ordem)->first();
        abort_unless($row,404,'Prestação não encontrada.');
        $plan=$this->operationPlan($db,$row,$d,$request->user());
        unset($plan['_cents'],$plan['_due'],$plan['_start'],$plan['_rate']);
        $plan['registo_activo']=CashAccountLedger::flag('CIS_COLLECTION_WRITES_APPROVED')
            && (!in_array($d['operacao'],self::CASH_OPS,true) || (CashAccountLedger::flag('CIS_CASH_WRITES_APPROVED') && CashAccountLedger::flag(CashAccountLedger::FLAG)));
        $plan['com_recebimento']=in_array($d['operacao'],self::CASH_OPS,true);
        return response()->json($plan)->header('Cache-Control','no-store, private');
    }

    public function operate(Request $request, string $numero, int $ordem)
    {
        $this->admin($request);
        abort_unless(CashAccountLedger::flag('CIS_COLLECTION_WRITES_APPROVED'),503,'As operações de cobrança ainda não foram activadas no servidor.');
        $d=$this->operationRules($request);
        $cash=in_array($d['operacao'],self::CASH_OPS,true);
        if ($cash) CashAccountLedger::requireReady();
        $extra=$request->validate([
            'idempotency_key'=>['required','uuid'],
            'confirmacao'=>['required','accepted'],
            'valor_recebido'=>[$cash?'required':'nullable','string','max:20'],
            'conta_id'=>[$cash?'required':'nullable','integer','min:1'],
            'referencia'=>[$cash?'required':'nullable','string','min:4','max:60'],
            'motivo'=>['nullable','string','max:200'],
        ]);
        $op=$d['operacao'];
        $key='contract:op:'.$op.':'.strtolower($extra['idempotency_key']);
        $user=$request->user();
        $ref=trim(preg_replace('/\s+/',' ',(string)($extra['referencia']??'')));
        $motivo=trim((string)($extra['motivo']??''));
        $db=DB::connection('mysql_contract_writer');
        $labels=['parcial'=>'pagamento_parcial','remanescente'=>'liquidacao_saldo','renovar_juros'=>'renovacao_juros_sem_pagamento',
            'capitalizar'=>'capitalizacao_juros','prorrogar_15_30'=>'prorrogacao_15_30'];
        try {
        $result=$db->transaction(function () use ($db,$d,$extra,$op,$cash,$numero,$ordem,$user,$key,$ref,$motivo,$labels) {
            $account=null;$before=0;
            if ($cash) {
                [$account,$before]=CashAccountLedger::lockActive($db,(int)$extra['conta_id']);
                $existing=$db->table('Extracto')->where('operation_key',$key)->first();
                if ($existing) {
                    abort_unless((string)$existing->Contracto===$numero&&(int)$existing->account_id===(int)$account->id,409,'A chave da operação já foi usada.');
                    return ['registado'=>true,'repetido'=>true,'extracto_nr'=>(int)$existing->Nr,'notice'=>'Operação já registada anteriormente; nada foi duplicado.'];
                }
            } elseif ($db->table('cis_contractos_historico')->where('operation_key',$key)->exists()) {
                return ['registado'=>true,'repetido'=>true,'notice'=>'Operação já registada anteriormente; nada foi duplicado.'];
            }
            $all=$db->table('Contractos')->where('Nr do Contracto',$numero)->lockForUpdate()->get();
            $row=$all->firstWhere('Ordem',$ordem);
            abort_unless($row&&$all->where('Ordem',$ordem)->count()===1,409,'Prestação não encontrada ou identificador duplicado.');
            if ($cash) $this->cashDateOpen($account,$d['data_pagamento']);
            $plan=$this->operationPlan($db,$row,$d,$user);
            $c=$plan['_cents'];
            if ($cash) {
                $received=CashAccountLedger::cents((string)$extra['valor_recebido'],'valor_recebido');
                abort_if($c['valor']===null,422,'Indique o valor recebido.');
                if ($received!==$c['valor']) throw ValidationException::withMessages(['valor_recebido'=>'O valor recebido não corresponde ao calculado no servidor ('.$plan['valor'].' MT).']);
            }
            $m=fn(int $cents)=>self::money($row,$cents);
            $q=$db->table('Contractos')->where('Ordem',$ordem)->where('Nr do Contracto',$numero)->whereIn('Estado',['Pendente','Pagamento Atrasado']);
            $upd=match ($op) {
                'parcial'=>['Valor de Juros'=>$m($c['juros']),'Valor de Multa'=>0,'Valor a Pagar'=>$m($c['valor']),'Valor Pago'=>$m($c['valor']),'Data de Pagamento'=>$plan['data_pagamento'],
                    'Estado'=>$plan['estado'],'Dias'=>$plan['dias_atraso'],'Situação de Pagamento'=>'Pago parcialmente - saldo na P'.$plan['nova_prestacao']['parcela']],
                'remanescente'=>['Valor de Juros'=>$m($c['juros']),'Valor de Multa'=>$m($c['multa']),'Valor a Pagar'=>$m($c['total']),'Valor Pago'=>$m($c['total']),
                    'Data de Pagamento'=>$plan['data_pagamento'],'Estado'=>$plan['estado'],'Dias'=>$plan['dias_atraso'],'Situação de Pagamento'=>'Pagamento Completo'],
                'renovar_juros'=>['Valor de Juros'=>$m($c['juros']),'Valor a Pagar'=>$m($c['total']),'Data de Cobrança'=>$plan['novo_vencimento'],
                    'Estado'=>self::stateFor($plan['_due']),'Dias'=>0,'Situação de Pagamento'=>'Juros renovados'],
                'capitalizar'=>['Capital'=>(int)round((float)$row->Capital+$c['capital_add']/100),
                    'Amortização'=>(int)round($c['principal']/100),'Valor de Juros'=>$m($c['juros']),'Valor a Pagar'=>$m($c['total']),
                    'Data de Inicio'=>$plan['novo_inicio'],'Data de Cobrança'=>$plan['novo_vencimento'],'Estado'=>self::stateFor($plan['_due']),'Dias'=>0,
                    'Situação de Pagamento'=>'Juros capitalizados'],
                'prorrogar_15_30'=>['Valor de Juros'=>$m($c['juros']),'Valor a Pagar'=>$m($c['total']),
                    'Data de Cobrança'=>$plan['novo_vencimento'],'Estado'=>self::stateFor($plan['_due']),'Dias'=>0,'Situação de Pagamento'=>'Prorrogado para 30 dias'],
            };
            // «% de Juro» é inteiro na tabela: só é actualizado com taxas inteiras (a taxa exacta fica no histórico).
            if (in_array($op,['capitalizar','prorrogar_15_30'],true) && isset($plan['taxa']) && abs((float)$plan['taxa']-round((float)$plan['taxa']))<0.00001) $upd['% de Juro']=(int)round((float)$plan['taxa']);
            if (in_array($op,['renovar_juros','capitalizar','prorrogar_15_30'],true)) {
                $finalDue=substr((string)($row->{'Data Final'}??''),0,10);
                if ($finalDue===''||$finalDue<$plan['novo_vencimento']) $upd['Data Final']=$plan['novo_vencimento'];
            }
            abort_unless($q->update($upd)===1,409,'A prestação foi alterada por outra operação.');
            if ($op==='prorrogar_15_30') {
                $db->table('Contractos')->where('Nr do Contracto',$numero)->update(['Prazo/Duração'=>'30 Dias']);
            }
            $newOrdem=null;
            if ($op==='parcial') {
                $np=$plan['nova_prestacao'];
                $new=(array)$row; unset($new['Ordem']);
                $capInt=(int)round($c['restante']/100);
                $new=array_merge($new,[
                    'Nº da Parcela'=>$np['parcela'],'Nº de Prestações'=>(int)($row->{'Nº de Prestações'}??1)+1,
                    'Data de Inicio'=>$np['data_inicio'],'Data de Cobrança'=>$np['data_vencimento'],'Data Final'=>$np['data_vencimento'],
                    'Capital'=>$capInt,'Amortização'=>$capInt,
                    'Valor de Juros'=>$m($c['novo_juros']),'Valor a Pagar'=>$m($c['restante']+$c['novo_juros']),
                    'Valor Pago'=>0,'Valor de Multa'=>0,'Data de Pagamento'=>null,'Estado'=>self::stateFor(CarbonImmutable::parse($np['data_vencimento'])),
                    'Estado do Contrato'=>'Em Progresso','Situação de Pagamento'=>'Saldo transitado da P'.$plan['parcela'],'Dias'=>0,
                    'usuario'=>mb_substr((string)$user->name,0,255),
                ]);
                if (abs($plan['_rate']-round($plan['_rate']))<0.00001) $new['% de Juro']=(int)round($plan['_rate']);
                [$sql,$bindings]=\App\Services\LegacyContractInsert::statement($new);
                $db->insert($sql,$bindings);
                $newOrdem=(int)$db->getPdo()->lastInsertId();
            }
            $nr=null;
            if ($cash) {
                $nr=$db->table('Extracto')->insertGetId([
                    'Contracto'=>$numero,'Cliente'=>mb_substr((string)$row->{'Nome do Cliente'},0,255),'Parcela'=>(string)$plan['parcela'],
                    'Data da Operação'=>$plan['data_pagamento'],'Valor'=>$plan['valor'],'Tipo de Movimento'=>'Entradas',
                    'Descrição'=>mb_substr('[CAIXA:COBRANCA] '.($op==='parcial'?'Pagamento parcial':'Liquidação do saldo').' '.$numero.' P'.$plan['parcela'].' | REF:'.$ref,0,255),
                    'usuario'=>mb_substr((string)$user->name,0,255),'account_id'=>(int)$account->id,'operation_key'=>$key,
                ],'Nr');
            }
            $after=$db->table('Contractos')->where('Ordem',$ordem)->first();
            $reasons=['parcial'=>'Pagamento parcial de '.($plan['valor']??'').' MT (juros '.($plan['juros_cobertos']??'').' MT); saldo na nova prestação P'.($plan['nova_prestacao']['parcela']??'').' à taxa '.($plan['taxa']??'').'%'.(!empty($plan['taxa_alterada'])?' (alterada pelo operador)':' (tabela)').'.','remanescente'=>'Liquidação do saldo após pagamentos parciais.',
                'renovar_juros'=>'Renovação dos juros sem pagamento; novo vencimento '.($plan['novo_vencimento']??'').'.',
                'capitalizar'=>'Capitalização dos juros; taxa '.($plan['taxa']??'').'%'.(!empty($plan['taxa_alterada'])?' (alterada pelo operador)':' (tabela)').'.',
                'prorrogar_15_30'=>'Prorrogação de 15 para 30 dias ('.($d['tratamento_juros']??'').' juros).'];
            ContractHistory::record($db,[
                'contract_number'=>$numero,'installment_ordem'=>$ordem,'installment_number'=>$plan['parcela'],'operation'=>$labels[$op],
                'before'=>$row,'after'=>$after,'extracto_nr'=>$nr,'account_id'=>$account?(int)$account->id:null,
                'amount'=>$cash?$plan['valor']:($plan['valor_a_pagar']??null),'evidence_reference'=>$cash?$ref:null,
                'reason'=>trim($reasons[$op].($motivo!==''?' Motivo: '.$motivo:'')),'operation_key'=>$key,'performed_by'=>(int)$user->id,
            ]);
            if ($newOrdem) {
                $newRow=$db->table('Contractos')->where('Ordem',$newOrdem)->first();
                abort_unless($newRow&&(string)$newRow->{'Nr do Contracto'}===$numero,409,'Falha ao criar a nova prestação.');
                ContractHistory::record($db,['contract_number'=>$numero,'installment_ordem'=>$newOrdem,'installment_number'=>$plan['nova_prestacao']['parcela'],
                    'operation'=>'parcial_nova_prestacao','before'=>null,'after'=>$newRow,'extracto_nr'=>$nr,'account_id'=>$account?(int)$account->id:null,
                    'amount'=>$plan['nova_prestacao']['valor_a_pagar'],'evidence_reference'=>$ref,
                    'reason'=>'Saldo de capital '.$plan['nova_prestacao']['capital'].' MT + juros '.$plan['nova_prestacao']['juros'].' MT ('.$plan['nova_prestacao']['taxa'].'%) após pagamento parcial da P'.$plan['parcela'].'.',
                    'operation_key'=>$key,'performed_by'=>(int)$user->id]);
            }
            $finished=false;
            if ($op==='remanescente') {
                $finished=$db->table('Contractos')->where('Nr do Contracto',$numero)->whereNotIn('Estado',['Pago','Pago em Atraso','Renovado'])->count()===0;
                if ($finished) $db->table('Contractos')->where('Nr do Contracto',$numero)->update(['Estado do Contrato'=>'Finalizado']);
            }
            unset($plan['_cents'],$plan['_due'],$plan['_start'],$plan['_rate']);
            return $plan+['registado'=>true,'repetido'=>false,'extracto_nr'=>$nr,'nova_ordem'=>$newOrdem,'conta'=>$account?->code,
                'saldo_conta_actual'=>$cash?CashAccountLedger::amount($before+$c['valor']):null,'contrato_finalizado'=>$finished,
                'notice'=>$cash?'Recebimento registado na conta '.$account->code.' com histórico antes/depois.':'Alteração registada com histórico antes/depois (sem movimento de Caixa).'];
        },3);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            abort(503,'A base de dados recusou a operação (MySQL '.($e->errorInfo[1]??'?').'). Nada foi gravado. '
                .'Active as permissões com: bash deploy/activate-collection-operations.sh');
        }
        return response()->json($result,empty($result['repetido'])?201:200)->header('Cache-Control','no-store, private');
    }


    /** Alterações de crédito e recuperação de saldo abatido. */
    public function creditChange(Request $request, string $numero)
    {
        $this->admin($request);
        abort_unless($request->user()->role === 'administrador', 403,
            'Esta operação requer aprovação do administrador.');
        $d = $request->validate([
            'operacao' => ['required','in:reforco,abate,recuperacao'],
            'ordem' => ['required_unless:operacao,abate','nullable','integer','min:1'],
            'valor' => ['required_unless:operacao,abate','nullable','string','max:16'],
            'taxa' => ['nullable','integer','min:0','max:100'],
            'confirmar' => ['nullable','boolean'],
            'valor_confirmado' => ['required_if:confirmar,true','nullable','string','max:20'],
            'idempotency_key' => ['required_if:confirmar,true','nullable','uuid'],
            'conta_id' => ['nullable','integer','min:1'],
            'motivo' => ['nullable','string','max:180'],
            'referencia' => ['nullable','string','max:60'],
        ]);
        $operation = $d['operacao'];
        $commit = (bool) ($d['confirmar'] ?? false);
        $cash = $operation !== 'abate';
        $amount = $cash ? CashAccountLedger::cents($d['valor'], 'valor') : 0;
        abort_if($cash && $amount <= 0, 422, 'Indique um montante positivo.');
        $date = now('Africa/Maputo')->toDateString();
        $reason = trim($d['motivo'] ?? '');
        $reference = trim($d['referencia'] ?? '');
        if ($commit) {
            abort_unless(CashAccountLedger::flag('CIS_COLLECTION_WRITES_APPROVED'), 503, 'A gravação de cobranças está desactivada.');
            abort_unless(isset($d['valor_confirmado']),422,'Confirme o valor da prévia.');
            abort_unless(!empty($d['idempotency_key']) && mb_strlen($reason) >= 5, 422, 'Indique o motivo da operação.');
            if ($cash) {
                CashAccountLedger::requireReady();
                abort_unless(!empty($d['conta_id']) && mb_strlen($reference) >= 4, 422, 'Indique uma conta activa e referência.');
            }
        }
        $db = DB::connection('mysql_contract_writer');
        $key = 'contract:credit:'.$operation.':'.strtolower($d['idempotency_key'] ?? '');
        $user = $request->user();

        return response()->json($db->transaction(function () use ($db,$numero,$d,$operation,$commit,$cash,$amount,$date,$reason,$reference,$key,$user) {
            $account = null;
            if ($commit && $cash) {
                [$account] = CashAccountLedger::lockActive($db, (int) $d['conta_id']);

            }
            $query = $db->table('Contractos')->where('Nr do Contracto',$numero)->orderBy('Ordem');
            $rows = ($commit ? $query->lockForUpdate() : $query)->get();
            abort_if($rows->isEmpty(),404,'Contrato não encontrado.');
            if ($commit) {
                $previous = $db->table('cis_contractos_historico')->where('operation_key',$key)->first();
                if ($previous) {
                    abort_unless((string)$previous->contract_number === $numero
                        && (!$cash || ((int)$previous->installment_ordem === (int)$d['ordem']
                        && (int)$previous->account_id === (int)$account->id
                        && CashAccountLedger::legacyCents($previous->amount) === $amount)),409,'A chave já pertence a outra operação.');
                    return ['registado'=>true,'repetido'=>true,'notice'=>'Operação já registada.'];
                }
            }
            if ($commit && $cash) $this->cashDateOpen($account,$date);
            if ($operation === 'abate') {
                $open = $rows->filter(fn ($row) => in_array($row->Estado,['Pendente','Pagamento Atrasado'],true));
                abort_if($open->isEmpty(),409,'Não existem prestações em aberto para abater.');
                $balances = [];
                foreach ($open as $row) {
                    $this->opBase($db,$row);
                    $due = $this->quote($row,$date,false)['_cents']['total'];
                    $balance = $due - $this->partialPaid($db,$row);
                    abort_unless($balance > 0,409,'Existe uma prestação sem saldo abatível.');
                    $balances[(int)$row->Ordem] = $balance;
                }
                if ($commit) {
                    abort_unless(CashAccountLedger::cents($d['valor_confirmado']) === array_sum($balances),409,'O saldo mudou. Actualize a prévia.');
                    foreach ($rows as $row) {
                        $order = (int)$row->Ordem;
                        $change = ['Estado do Contrato'=>'Abatido'];
                        if (isset($balances[$order])) {
                            $change += ['Estado'=>'Abatido','Situação de Pagamento'=>'Abatido'];
                            if (!$this->partialPaid($db,$row)) $change['Valor Pago'] = 0;
                        }
                        $line = $db->table('Contractos')->where('Nr do Contracto',$numero)->where('Ordem',$order);
                        $line->update($change);
                        ContractHistory::record($db,[
                            'contract_number'=>$numero,'installment_ordem'=>$order,'installment_number'=>$row->{'Nº da Parcela'},
                            'operation'=>'abate_credito','before'=>$row,'after'=>$line->first(),
                            'amount'=>isset($balances[$order])?CashAccountLedger::amount($balances[$order]):null,
                            'reason'=>$reason,'operation_key'=>$key,'performed_by'=>$user->id,
                        ]);
                    }
                }
                return ['registado'=>$commit,'valor'=>CashAccountLedger::amount(array_sum($balances)),
                    'prestacoes'=>count($balances),'notice'=>$commit?'Contrato abatido com saldo disponível para recuperação.':'Abate do saldo em aberto, sem movimento na Caixa.'];
            }

            $selected = $rows->where('Ordem',(int)$d['ordem']);
            abort_unless($selected->count()===1,404,'Prestação não encontrada ou duplicada.');
            $row = $selected->first();
            $order = (int)$row->Ordem;
            $changes = [];
            $details = [];
            if ($operation === 'reforco') {
                $this->opBase($db,$row);
                abort_unless(mb_strtolower(trim((string)$row->{'Tipo de Juro'})) === 'fixo',409,'O reforço directo só se aplica a juro fixo.');
                abort_unless($this->partialPaid($db,$row)===0,409,'A prestação tem pagamentos parciais, que devem ser regularizados antes do reforço.');
                abort_unless($amount % 100 === 0,422,'O reforço de capital deve ser em meticais inteiros.');
                $capital = (int)$row->Capital;
                $principal = (int)$row->{'Amortização'};
                abort_unless($capital>0 && $principal>0 && $principal<=$capital,409,'Capital ou amortização da prestação não identificável.');
                $increment = intdiv($amount,100);
                abort_unless($capital+$increment<=2147483647,422,'O capital ultrapassa o limite do contrato.');
                $newPrincipal = $principal+$increment;
                [$unit,$length,$term] = $this->renewalTerm($db,$row);
                $period = match (true) {
                    $unit==='days' && in_array($length,[14,15],true) => 15,
                    ($unit==='months' && $length===1) || ($unit==='days' && $length===30) => 30,
                    default => null,
                };
                $tableRate = null;
                if ($period !== null) {
                    $rates = DB::table('cis_tabela_juros')->where('periodo_referencia',$period)
                        ->where('limite_inferior','<=',$newPrincipal)->where('limite_superior','>=',$newPrincipal)
                        ->limit(2)->pluck('taxa');
                    if ($rates->count()===1 && is_numeric($rates[0]) && floor((float)$rates[0])===(float)$rates[0]) {
                        $tableRate = (int)$rates[0];
                    }
                }
                $rate = $d['taxa'] ?? $tableRate ?? $row->{'% de Juro'};
                abort_unless(is_numeric($rate) && (float)$rate>=0 && (float)$rate<=100,409,'Indique uma taxa de juro válida.');
                $rate = (int)$rate;
                $interest = self::isCisContract($row)
                    ? LegacyContractPricing::upwards($newPrincipal*$rate/100)*100
                    : (int) round($newPrincipal*$rate);
                $fine = CashAccountLedger::legacyCents($row->{'Valor de Multa'} ?? 0);
                $total = $newPrincipal*100+$interest+$fine;
                $changes = ['Capital'=>$capital+$increment,'Amortização'=>$newPrincipal,'% de Juro'=>$rate,
                    'Valor de Juros'=>self::money($row,$interest),'Valor a Pagar'=>self::money($row,$total),'Valor Pago'=>0];
                $details = ['capital'=>CashAccountLedger::amount($newPrincipal*100),'juros'=>CashAccountLedger::amount($interest),
                    'valor_a_pagar'=>CashAccountLedger::amount($total),'taxa'=>$rate,
                    'taxa_tabela'=>$tableRate,'periodo_referencia'=>$period,'prazo'=>$term,
                    'origem_taxa'=>isset($d['taxa'])?'manual':($tableRate!==null?'tabela':'prestacao'),
                    'vencimento'=>self::date($row->{'Data de Cobrança'},'Data de vencimento')->toDateString()];
                if ($commit) {
                    [, $available] = CashAccountLedger::availableOn($db,$account,$date);
                    abort_unless($amount <= $available,422,'Saldo insuficiente na conta seleccionada.');
                }
            } else {
                abort_unless($row->Estado==='Abatido' && $row->{'Estado do Contrato'}==='Abatido',409,'A prestação não está abatida.');
                $history = $db->table('cis_contractos_historico')->where('contract_number',$numero)->where('installment_ordem',$order);
                $original = (clone $history)->where('operation','abate_credito')->value('amount');
                $recovered = (clone $history)->where('operation','recuperacao_credito')->sum('amount');
                $balance = CashAccountLedger::legacyCents($original ?? 0)-CashAccountLedger::legacyCents($recovered);
                abort_unless($amount <= $balance,422,'O montante excede o saldo abatido recuperável.');
                $details = ['saldo_recuperavel'=>CashAccountLedger::amount($balance),'saldo_apos_operacao'=>CashAccountLedger::amount($balance-$amount)];
            }
            if ($commit) {
                abort_unless(CashAccountLedger::cents($d['valor_confirmado']) === ($operation==='reforco' ? $total : $balance-$amount),409,'O saldo mudou. Actualize a prévia.');
                $line = $db->table('Contractos')->where('Nr do Contracto',$numero)->where('Ordem',$order);
                if ($changes) $line->update($changes);
                $isOutgoing = $operation === 'reforco';
                $nr = $db->table('Extracto')->insertGetId([
                    'Contracto'=>$numero,'Cliente'=>mb_substr((string)$row->{'Nome do Cliente'},0,255),
                    'Parcela'=>(string)$row->{'Nº da Parcela'},'Data da Operação'=>$date,'Valor'=>CashAccountLedger::amount($amount),
                    'Tipo de Movimento'=>$isOutgoing?'Saídas':'Entradas',
                    'Descrição'=>($isOutgoing?'Aumento de Crédito':'Recuperação de Crédito Abatido').' | REF:'.$reference,
                    'usuario'=>mb_substr((string)$user->name,0,255),'account_id'=>$account->id,'operation_key'=>$key,
                ],'Nr');
                ContractHistory::record($db,[
                    'contract_number'=>$numero,'installment_ordem'=>$order,'installment_number'=>$row->{'Nº da Parcela'},
                    'operation'=>$isOutgoing?'reforco_credito':'recuperacao_credito','before'=>$row,'after'=>$line->first(),
                    'extracto_nr'=>$nr,'account_id'=>$account->id,'amount'=>CashAccountLedger::amount($amount),
                    'evidence_reference'=>$reference,'reason'=>$reason,'operation_key'=>$key,'performed_by'=>$user->id,
                ]);
            }
            return $details+['registado'=>$commit,'valor'=>CashAccountLedger::amount($amount),
                'notice'=>$commit?'Operação registada no Extracto e no histórico.':'Prévia da operação.'];
        },3))->header('Cache-Control','no-store, private');
    }

    /** Histórico imutável da prestação/contrato (leitura). */
    public function history(Request $request, string $numero)
    {
        \App\Support\Permissions::require($request->user(),'ver');
        try {
            $db=DB::connection('mysql_legacy');
            $rows=$db->getSchemaBuilder()->hasTable('cis_contractos_historico')
                ? $db->table('cis_contractos_historico')->whereIn('contract_number',\App\Services\ContractNumbering::aliases($numero))
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
