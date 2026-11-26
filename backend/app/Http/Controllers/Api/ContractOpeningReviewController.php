<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\ContractCalculationStrategy;

final class ContractOpeningReviewController extends Controller
{
    /**
     * Validação de abertura SOMENTE LEITURA. Não atribui número de contrato,
     * não gera parcelas reais, não lança desembolsos e não modifica Extracto.
     * A revisão exacta de Contractos.frm é obrigatória antes de autorizar escrita.
     */
    public function cashBalance(Request $request)
    {
        $user=$request->user();
        \App\Support\Permissions::require($user,'contratos.abrir');
        abort_unless(filter_var(env('CIS_PRIVATE_PREVIEW',false),FILTER_VALIDATE_BOOLEAN)
            && filter_var(env('CIS_CONTRACTS_READ_APPROVED',false),FILTER_VALIDATE_BOOLEAN),503);
        $rows=DB::connection('mysql_legacy')->table('Extracto')
            ->get(['Tipo de Movimento','Valor']);
        $saldo=0.0;
        foreach ($rows as $row) {
            $amount=(float)$row->Valor;
            abort_unless(is_finite($amount) && $amount>=0,409,
                'Extracto contém valores inconsistentes; não é possível determinar o saldo.');
            $saldo += $row->{'Tipo de Movimento'}==='Entradas'?$amount:-$amount;
        }
        return response()->json(['saldo_disponivel'=>max(0,floor($saldo)),
            'saldo_contabil'=>round($saldo,2),
            'consulta_apenas'=>true])->header('Cache-Control','no-store, private');
    }

    /** Saldo de uma conta da Caixa numa data (para desembolsos com data passada). Só leitura. */
    public function accountBalanceOn(Request $request)
    {
        $user=$request->user();
        \App\Support\Permissions::require($user,'contratos.abrir');
        $q=$request->validate(['conta_id'=>['required','integer','min:1'],'data'=>['required','date_format:Y-m-d']]);
        return response()->json($this->balanceOn((int)$q['conta_id'],$q['data'],$user))->header('Cache-Control','no-store, private');
    }

    private function balanceOn(int $accountId,string $date,$user): array
    {
        $today=now('Africa/Maputo')->toDateString();
        $out=['data'=>$date,'hoje'=>$date===$today,'pode_data_passada'=>\App\Support\Permissions::can($user,'contratos.data'),
            'saldo_no_dia'=>null,'disponivel'=>null,'problema'=>null];
        if ($date>$today) { $out['problema']='A data do desembolso não pode ser futura.'; return $out; }
        if ($date!==$today && !$out['pode_data_passada']) { $out['problema']='O seu perfil só pode registar desembolsos com a data de hoje.'; return $out; }
        $account=DB::table('cis_cash_accounts')->where('id',$accountId)->first();
        if (!$account) { $out['problema']='Conta da Caixa inexistente.'; return $out; }
        try {
            if ($date!==$today) \App\Services\CashAccountLedger::requireDateOpen($account,$date,'registar o desembolso');
            [$at,$avail]=$date===$today
                ? array_fill(0,2,\App\Services\CashAccountLedger::balance(DB::connection(),$account))
                : \App\Services\CashAccountLedger::availableOn(DB::connection(),$account,$date);
            $out['saldo_no_dia']=\App\Services\CashAccountLedger::amount($at);
            $out['disponivel']=\App\Services\CashAccountLedger::amount($avail);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $out['problema']=$e->getMessage();
        }
        return $out;
    }

    /**
     * Apenas PRÉVIA do próximo número: não faz reserva nem altera registos.
     * O número definitivo só é atribuído na transacção de abertura, sob lock.
     */
    public function suggestedNumber(Request $request)
    {
        $user=$request->user();
        \App\Support\Permissions::require($user,'contratos.abrir');
        abort_unless(
            filter_var(env('CIS_PRIVATE_PREVIEW',false),FILTER_VALIDATE_BOOLEAN)
            && filter_var(env('CIS_CONTRACTS_READ_APPROVED',false),FILTER_VALIDATE_BOOLEAN),
            503,'Consulta de contratos não disponível neste ambiente.'
        );
        $db=DB::connection('mysql_legacy');
        $max=$db->table('Contractos')->max('Nº');
        abort_if($max!==null && (!is_numeric($max) || (float)$max<0
            || (float)$max>999999998),409,'Sequência de contratos inválida ou esgotada.');
        $sequence=(int)($max??0)+1;
        $numero=\App\Services\ContractNumbering::active()
            ? \App\Services\ContractNumbering::next($db,preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$request->query('data_inicio'))?(string)$request->query('data_inicio'):now('Africa/Maputo')->toDateString())
            : 'C-'.str_pad((string)$sequence,4,'0',STR_PAD_LEFT);
        abort_if($db->table('Contractos')->where('Nr do Contracto',$numero)->exists(),
            409,'O número sugerido já está em uso; é necessária reconciliação.');
        return response()->json([
            'numero_sugerido'=>$numero,
            'provisorio'=>true,
            'mensagem'=>'Número calculado pelo maior Nº actual; confirmado apenas na gravação.',
        ])->header('Cache-Control','no-store, private');
    }

    public function validateDraft(Request $request)
    {
        $user = $request->user();
        \App\Support\Permissions::require($user,'contratos.abrir');
        abort_unless(filter_var(env('CIS_PRIVATE_PREVIEW', false), FILTER_VALIDATE_BOOLEAN), 503);
        abort_unless(filter_var(env('CIS_CLIENTES_PREVIEW_APPROVED', false), FILTER_VALIDATE_BOOLEAN), 503);
        abort_unless(filter_var(env('CIS_CONTRACTS_READ_APPROVED', false), FILTER_VALIDATE_BOOLEAN), 503);

        $values = $request->validate([
            'cliente_id' => ['required', 'integer', 'min:1'],
            'montante' => ['required', 'integer', 'min:1', 'max:16777216'],
            'tipo_juro' => ['required','in:Fixo,Variável,Composto'],
            'estrategia_calculo' => ['required','string','max:90'],
            'periodo_dias' => ['required','integer','in:15,30'],
            'prazo' => ['required','in:1 Semana,2 Semanas,3 Semanas,1 Mês,2 Meses,3 Meses'],
            'taxa_informada' => ['required','numeric','between:0,100'],
            'taxa_multa' => ['required','numeric','between:0,100'],
            'prazo_meses' => ['required','integer','min:0','max:3'],
            'modalidade' => ['required','in:Semanal,Mensal,Uma Parcela'],
            'forma_pagamento' => ['required','string','max:100'],
            'disponibilidade' => ['required','string','max:100'],
            'data_inicio' => ['required','date_format:Y-m-d'],
            'conta_id' => ['sometimes','nullable','integer','min:1'],
            'proposta_id' => ['sometimes','nullable','integer','min:1'],
        ]);

        $rows = DB::connection('mysql_legacy')->table('Clientes')
            ->where('ID', $values['cliente_id'])->limit(2)
            ->get(['ID','Numeração','Nome Completo','Cidade']);
        abort_if($rows->count() !== 1, 422, 'Mutuário inexistente ou identificador duplicado.');

        $issues = [];
        $rate = null;
        if ($black = \App\Support\ClientRegistry::blacklisted((int) $values['cliente_id'])) {
            $issues[] = 'O mutuário está na lista negra (central de risco): '.$black->motivo.'.';
        }
        if (!in_array($values['estrategia_calculo'], ContractCalculationStrategy::OPTIONS, true)) {
            $issues[] = 'Estratégia de cálculo não reconhecida.';
        } elseif (!ContractCalculationStrategy::compatible($values['tipo_juro'], $values['estrategia_calculo'])) {
            $issues[] = 'A estratégia escolhida não é compatível com o tipo de juro.';
        }
        $isExtended = in_array($values['estrategia_calculo'], [
            ContractCalculationStrategy::TOTAL,
            ContractCalculationStrategy::SIMPLE,
            ContractCalculationStrategy::DECLINING,
        ], true);
        if ($isExtended && !filter_var(env('CIS_EXTENDED_CALCULATIONS_APPROVED', false), FILTER_VALIDATE_BOOLEAN)) {
            $issues[] = 'A fórmula adicional está implementada para pré-visualização, mas a gravação financeira exige a activação de CIS_EXTENDED_CALCULATIONS_APPROVED após testes.';
        }
        if (!DB::connection('mysql_legacy')->getSchemaBuilder()->hasColumn('Contractos','Estratégia de Cálculo')) {
            $issues[] = 'A nova coluna Estratégia de Cálculo ainda não foi criada na tabela Contractos. Execute a migração autorizada.';
        }
        $terms = (int)$values['periodo_dias']===15
            ? ['1 Semana','2 Semanas']
            : ['1 Semana','2 Semanas','3 Semanas','1 Mês','2 Meses','3 Meses'];
        if (!in_array($values['prazo'], $terms, true)) {
            $issues[] = 'O prazo não é compatível com o período de juros seleccionado.';
        }
        $isWeekly = str_contains($values['prazo'],'Semana');
        $expectedMonths = $isWeekly ? 0 : (int)explode(' ',$values['prazo'])[0];
        if ((int)$values['prazo_meses'] !== $expectedMonths) {
            $issues[] = 'O prazo enviado não corresponde à duração indicada.';
        }
        if (($isWeekly || $values['modalidade']==='Semanal')
            && !filter_var(env('CIS_WEEKLY_CONTRACTS_APPROVED',false),FILTER_VALIDATE_BOOLEAN)) {
            $issues[] = 'O cálculo semanal está preparado, mas precisa de testes e activação específica para criar e desembolsar.';
        }
        if (($isWeekly || $values['modalidade']==='Semanal')
            && $values['estrategia_calculo']===ContractCalculationStrategy::SIMPLE) {
            $issues[] = 'A prestação fixa simples cobra juros por prestação: com frequência semanal é preciso definir e aprovar uma taxa semanal.';
        }
        $allowedModalities = match ($values['prazo']) {
            '1 Semana' => ['Uma Parcela'],
            '2 Semanas', '3 Semanas', '1 Mês' => ['Semanal','Uma Parcela'],
            '2 Meses','3 Meses' => ['Semanal','Mensal','Uma Parcela'],
            default => [],
        };
        if (!in_array($values['modalidade'],$allowedModalities,true)) {
            $issues[] = 'A modalidade de pagamento não é compatível com o prazo seleccionado.';
        }
        if ($values['tipo_juro']==='Composto' && $values['modalidade']==='Semanal') {
            $issues[] = 'É necessário definir a conversão da taxa de 15/30 dias para juro composto semanal.';
        }
        if ($values['tipo_juro'] === 'Fixo') {
            if (!isset($values['periodo_dias'])) {
                $issues[] = 'Seleccionar o período em dias para consultar a tabela de juros.';
            } elseif (!Schema::hasTable('cis_tabela_juros')) {
                $issues[] = 'Tabela de juros ainda não activada no MySQL.';
            } else {
                $prices = DB::table('cis_tabela_juros')
                    ->where('limite_inferior','<=',$values['montante'])
                    ->where('limite_superior','>=',$values['montante'])
                    ->where('periodo_referencia',$values['periodo_dias'])
                    ->get(['taxa']);
                if ($prices->count() !== 1) {
                    $issues[] = 'Não existe uma taxa única para este montante e período.';
                } else {
                    $rate = (string) $prices[0]->taxa;
                    if (isset($values['taxa_informada']) &&
                        abs((float)$values['taxa_informada'] - (float)$rate) > 0.0001) {
                        $issues[] = 'A taxa submetida não corresponde à tabela de preços.';
                    }
                }
            }
        } else {
            // Contractos.frm deixa a taxa de Variável e Composto ao utilizador;
            // os cálculos só serão gravados no endpoint financeiro protegido.
            if (!is_numeric($values['taxa_informada'])) {
                $issues[] = 'É necessária uma taxa manual válida.';
            }
        }
        foreach (['taxa_informada','taxa_multa'] as $column) {
            if (abs((float)$values[$column] - round((float)$values[$column])) > 0.000001) {
                $issues[] = 'As taxas do MySQL original usam INT; valores decimais exigem migração autorizada.';
            }
        }
        if ($values['tipo_juro'] === 'Variável' && $values['modalidade'] !== 'Uma Parcela') {
            $issues[] = 'Modalidade incompatível com a regra de preparação para juro variável.';
        }
        if ($values['tipo_juro'] === 'Composto' && $values['modalidade'] !== 'Mensal') {
            $issues[] = 'Modalidade incompatível com a regra de preparação para juro composto.';
        }

        // Proposta de crédito aprovada (obrigatória quando as propostas estão instaladas).
        if (ProposalController::ready()) {
            try {
                ProposalController::checkForOpening(isset($values['proposta_id'])?(int)$values['proposta_id']:null,$values,$request->user());
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $issues[]=$e->getMessage();
            }
        }
        // Data do desembolso e saldo da conta nesse dia.
        if (!empty($values['conta_id'])) {
            $b=$this->balanceOn((int)$values['conta_id'],$values['data_inicio'],$request->user());
            if ($b['problema']) $issues[]=$b['problema'];
            elseif ((float)$b['disponivel'] < (float)$values['montante']) {
                $issues[]='Saldo insuficiente na conta nessa data: '.$b['saldo_no_dia'].' MT'
                    .((float)$b['disponivel']<(float)$b['saldo_no_dia']?' (só '.$b['disponivel'].' MT podem sair sem deixar a conta negativa nos dias seguintes)':'').'.';
            }
        } elseif ($values['data_inicio']!==now('Africa/Maputo')->toDateString() && !\App\Support\Permissions::can($request->user(),'contratos.data')) {
            $issues[]='O seu perfil só pode registar desembolsos com a data de hoje.';
        }
        // Mesmas condições do endpoint de abertura (que volta a verificar tudo ao gravar).
        $on = fn (string $f) => filter_var(env($f, false), FILTER_VALIDATE_BOOLEAN);
        $blocked = [];
        foreach (['CIS_PRIVATE_PREVIEW','CIS_CLIENTES_PREVIEW_APPROVED','CIS_CONTRACTS_READ_APPROVED',
            'CIS_NEW_CONTRACTS_APPROVED','CIS_CASH_WRITES_APPROVED','CIS_CASH_CONTRACT_FLOWS_APPROVED'] as $flag) {
            if (!$on($flag)) $blocked[] = $flag;
        }
        try {
            \App\Services\CashAccountLedger::requireReady();
        } catch (\Throwable) {
            $blocked[] = 'caixa';
        }
        $canOpen = \App\Support\Permissions::can($request->user(), 'contratos.abrir');
        $authorized = !$blocked && $canOpen && count($issues) === 0;
        // Sem taxa nem valor financeiro calculado de forma inferida.
        return response()->json([
            'cadastro_confirmado' => true,
            'cliente' => [
                'id' => $rows[0]->ID,
                'numero' => $rows[0]->{'Numeração'},
                'nome' => $rows[0]->{'Nome Completo'},
            ],
            'taxa_tabela' => $rate,
            'problemas' => $issues,
            'dados_verificados' => count($issues) === 0,
            'autorizado_para_gravar' => $authorized,
            'pendencias' => [
                'O servidor verificará novamente saldo disponível, taxa, mutuário e esquema antes de gravar.',
                'Todas as quantias e parcelas são inteiras; os juros são arredondados para cima e o capital é reconciliado integralmente.',
                $authorized ? 'Escolha a conta de saída, indique a referência e confirme o desembolso.'
                    : (!$canOpen ? 'O seu perfil não pode abrir contratos.' : ($blocked ? 'Abertura com desembolso ainda não activada neste servidor.' : 'Corrija as incompatibilidades indicadas.')),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
