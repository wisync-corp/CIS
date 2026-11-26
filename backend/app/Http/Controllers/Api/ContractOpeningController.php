<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LegacyContractPricing;
use App\Services\LegacyContractInsert;
use App\Services\ContractCalculationStrategy;
use App\Services\CashAccountLedger;
use App\Services\ContractHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ContractOpeningController extends Controller
{
    /**
     * Nova abertura autorizada. Somente INSERT em Contractos e Extracto.
     * Não altera uma linha histórica. Operação atómica e privada.
     *
     * Activação exige CIS_NEW_CONTRACTS_APPROVED=true e utilizador dedicado
     * com privilégios SELECT limitados + INSERT em ambas as tabelas legadas.
     */
    public function store(Request $request)
    {
        // O desembolso sai OBRIGATORIAMENTE de uma conta activa da Caixa:
        // saldo da conta (não o agregado do Extracto), account_id, chave idempotente
        // e histórico imutável de cada prestação criada, na mesma transacção.
        $user = $request->user();
        \App\Support\Permissions::require($user,'contratos.abrir');
        foreach (['CIS_PRIVATE_PREVIEW','CIS_CLIENTES_PREVIEW_APPROVED',
            'CIS_CONTRACTS_READ_APPROVED','CIS_NEW_CONTRACTS_APPROVED'] as $flag) {
            abort_unless(filter_var(env($flag,false), FILTER_VALIDATE_BOOLEAN), 503,
                'Abertura real ainda não activada neste ambiente privado.');
        }
        CashAccountLedger::requireReady();

        $d = $request->validate([
            'cliente_id'=>['required','integer','min:1'],
            'montante'=>['required','integer','min:1','max:16777216'],
            'tipo_juro'=>['required','in:Fixo,Variável,Composto'],
            'estrategia_calculo'=>['required','string','max:90'],
            'periodo_dias'=>['required','integer','in:15,30'],
            'prazo'=>['required','in:1 Semana,2 Semanas,3 Semanas,1 Mês,2 Meses,3 Meses'],
            'taxa_informada'=>['required','numeric','between:0,100'],
            'taxa_multa'=>['required','numeric','between:0,100'],
            'prazo_meses'=>['required','integer','min:0','max:3'],
            'modalidade'=>['required','in:Semanal,Mensal,Uma Parcela'],
            'forma_pagamento'=>['required','in:Transferência/Depósito Bancário,Transferência Móvel,Numerário'],
            'disponibilidade'=>['required','in:Transferência/Depósito Bancário,Transferência Móvel,Numerário'],
            'data_inicio'=>['required','date_format:Y-m-d'],
            'confirmacao_desembolso'=>['required','accepted'],
            'conta_id'=>['required','integer','min:1'],
            'referencia'=>['required','string','min:4','max:60'],
            'idempotency_key'=>['required','uuid'],
        ]);
        $key='contract:open:'.strtolower($d['idempotency_key']);
        $ref=trim(preg_replace('/\s+/',' ',$d['referencia']));
        if (!in_array($d['estrategia_calculo'],ContractCalculationStrategy::OPTIONS,true)
            || !ContractCalculationStrategy::compatible($d['tipo_juro'],$d['estrategia_calculo'])) {
            throw ValidationException::withMessages(['estrategia_calculo'=>
                'Esta estratégia não está homologada para este tipo de juro no motor VBA.']);
        }
        if (in_array($d['estrategia_calculo'], [
            ContractCalculationStrategy::TOTAL,
            ContractCalculationStrategy::SIMPLE,
            ContractCalculationStrategy::DECLINING,
        ], true) && !filter_var(env('CIS_EXTENDED_CALCULATIONS_APPROVED', false), FILTER_VALIDATE_BOOLEAN)) {
            abort(503,'A estratégia adicional ainda não foi activada para gravação financeira após os testes.');
        }
        $validTerms=(int)$d['periodo_dias']===15
            ? ['1 Semana','2 Semanas']
            : ['1 Semana','2 Semanas','3 Semanas','1 Mês','2 Meses','3 Meses'];
        if (!in_array($d['prazo'],$validTerms,true)) {
            throw ValidationException::withMessages(['prazo'=>
                'Prazo incompatível com o período escolhido.']);
        }
        $allowedModalities=match ($d['prazo']) {
            '1 Semana' => ['Uma Parcela'],
            '2 Semanas','3 Semanas','1 Mês' => ['Semanal','Uma Parcela'],
            '2 Meses','3 Meses' => ['Semanal','Mensal','Uma Parcela'],
            default => [],
        };
        if (!in_array($d['modalidade'],$allowedModalities,true)) {
            throw ValidationException::withMessages(['modalidade'=>
                'Modalidade incompatível com o prazo seleccionado.']);
        }
        $isWeeklyTerm=str_contains($d['prazo'],'Semana');
        $requiresWeekly=$isWeeklyTerm || $d['modalidade']==='Semanal';
        if ($requiresWeekly && $d['estrategia_calculo']===ContractCalculationStrategy::SIMPLE) {
            throw ValidationException::withMessages(['estrategia_calculo'=>
                'Prestação fixa simples semanal exige política específica da taxa por período; abertura bloqueada.']);
        }
        if ($requiresWeekly && !filter_var(env('CIS_WEEKLY_CONTRACTS_APPROVED',false),FILTER_VALIDATE_BOOLEAN)) {
            abort(503,'Abertura semanal disponível apenas após os testes e aprovação específica do calendário semanal.');
        }
        if ($d['tipo_juro']==='Composto' && $d['modalidade']==='Semanal') {
            throw ValidationException::withMessages(['modalidade'=>
                'Capitalização composta semanal ainda carece de política explícita da taxa de 15/30 dias.']);
        }
        if ((int)explode(' ',$d['prazo'])[0] !== (int)$d['prazo_meses'] && !$isWeeklyTerm
            || ($isWeeklyTerm && (int)$d['prazo_meses']!==0)) {
            throw ValidationException::withMessages(['prazo'=>'Prazo e meses inconsistentes.']);
        }
        // Esquema antigo: juros/multa INT. Não truncar percentagens silenciosamente.
        foreach (['taxa_informada','taxa_multa'] as $taxa) {
            if (abs((float)$d[$taxa] - round((float)$d[$taxa])) > 0.000001) {
                throw ValidationException::withMessages([$taxa=>
                    'A coluna financeira original é INT e não permite taxas com casas decimais.']);
            }
        }
        // A data da operação é a data efectiva de desembolso, nunca uma
        // data histórica inserida retroactivamente ou futura.
        $today=now('Africa/Maputo')->toDateString();
        if ($d['data_inicio'] > $today) {
            throw ValidationException::withMessages(['data_inicio'=>'A data do desembolso não pode ser futura.']);
        }
        if ($d['data_inicio'] !== $today && !\App\Support\Permissions::can($user,'contratos.data')) {
            throw ValidationException::withMessages(['data_inicio'=>
                'O seu perfil só pode registar desembolsos com a data de hoje.']);
        }
        if ($d['tipo_juro']==='Variável' && $d['modalidade']!=='Uma Parcela' ||
            $d['tipo_juro']==='Composto' && $d['modalidade']!=='Mensal') {
            throw ValidationException::withMessages(['modalidade'=>'Modalidade incompatível com o tipo de juro.']);
        }

        // Proposta de crédito aprovada: obrigatória quando as propostas estão instaladas.
        // Reservada antes do desembolso e libertada se nada for gravado.
        $proposalId=null;$proposalReserved=false;
        if (ProposalController::ready()) {
            $proposalId=(int)$request->input('proposta_id')?:null;
            $prop=$proposalId?DB::table('cis_credit_proposals')->where('id',$proposalId)->first():null;
            $repeat=$prop && $prop->status==='desembolsada' && $prop->contract_number
                && DB::connection('mysql_legacy')->table('Extracto')->where('operation_key',$key)->exists();
            if (!$repeat) {
                ProposalController::checkForOpening($proposalId,$d,$user);
                ProposalController::reserve($proposalId);
                $proposalReserved=true;
            }
        }
        $db = DB::connection('mysql_contract_writer');
        $lock = $db->selectOne('SELECT GET_LOCK(?, 10) AS acquired', ['cis_open_new_contract']);
        if (!($lock && (int)$lock->acquired === 1) && $proposalReserved) ProposalController::release($proposalId);
        abort_unless($lock && (int)$lock->acquired === 1, 409,
            'Outra abertura está a decorrer. Aguarde e consulte a carteira antes de repetir.');
        try {
            $result = $db->transaction(function () use ($db,$d,$user,$key,$ref) {
                // Ordem de lock: conta -> registos (igual à cobrança).
                [$account,$accountBalance]=CashAccountLedger::lockActive($db,(int)$d['conta_id']);
                $previous=$db->table('Extracto')->where('operation_key',$key)->first();
                if ($previous) {
                    abort_unless((int)$previous->account_id===(int)$account->id,409,
                        'A chave da operação já foi usada noutra conta.');
                    return ['numero'=>(string)$previous->Contracto,'repetido'=>true,
                        'desembolso'=>CashAccountLedger::amount(CashAccountLedger::cents((string)$previous->Valor)),
                        'conta'=>$account->code,'estado'=>'Em Progresso',
                        'notice'=>'Desembolso já registado anteriormente; nada foi duplicado.'];
                }
                // Só escrever quando o esquema legado preserva as colunas
                // com os tipos conhecidos e PK autoincremental; sem migrações.
                $schema = DB::connection('mysql_contract_writer')->select(
                    "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, EXTRA, COLUMN_TYPE
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('Contractos','Extracto')"
                );
                $types = [];
                foreach ($schema as $col) {
                    $types[$col->TABLE_NAME][$col->COLUMN_NAME] = $col;
                }
                $engines=$db->select("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('Contractos','Extracto')");
                abort_unless(count($engines)===2 && collect($engines)->every(
                    fn ($table) => strtoupper((string)$table->ENGINE)==='INNODB'
                ),503,'Ambas as tabelas financeiras devem usar InnoDB para garantir rollback.');
                foreach (['Contractos'=>['Ordem','Nr do Contracto','Nº',
                    'Nome do Cliente','Nº de Cliente','Quantia Creditada','M. Pagamento',
                    'Prazo/Duração','% de Juro','Tipo de Juro','% de Multa',
                    'Forma de Pagamento','Disponibilidade','Data de Inicio',
                    'Cidade','Data Final','Data de Cobrança','Capital',
                    'Amortização','Valor a Pagar','Data de Pagamento','Valor de Juros',
                    'Valor de Multa','Valor Pago','Estado','Codigo Cliente',
                    'Estado do Contrato','Nº da Parcela','Nº de Prestações',
                    'Estratégia de Cálculo',
                    'Column1','Dias','Intervalo','Situação de Pagamento','usuario'],
                    'Extracto'=>['Nr','Contracto','Cliente','Parcela','Data da Operação',
                        'Valor','Tipo de Movimento','Descrição','usuario']] as $table=>$columns) {
                    foreach ($columns as $column) {
                        abort_unless(isset($types[$table][$column]),503,
                            'Esquema legado incompatível; nenhum registo foi gravado.');
                    }
                }
                abort_unless(str_contains(strtolower($types['Contractos']['Ordem']->EXTRA),'auto_increment')
                    && str_contains(strtolower($types['Extracto']['Nr']->EXTRA),'auto_increment'),503,
                    'IDs legados sem AUTO_INCREMENT; nenhum registo foi gravado.');
                foreach (['Capital','Amortização'] as $c) {
                    abort_unless(in_array(strtolower($types['Contractos'][$c]->DATA_TYPE),
                        ['int','integer'],true),503,'Tipo legado diferente do verificado.');
                }

                $buyer = $db->table('Clientes')->where('ID',$d['cliente_id'])
                    ->limit(2)
                    ->get(['ID','Nome Completo','Cidade']);
                abort_if($buyer->count()!==1,422,
                    'Mutuário inexistente ou identificador duplicado.');
                $buyer = $buyer[0];
                abort_if(!trim((string)$buyer->{'Nome Completo'}),422,'Mutuário sem nome.');
                \App\Support\ClientRegistry::requireClear((int)$buyer->ID);

                $rate=(float)$d['taxa_informada'];
                $band='';
                if ($d['tipo_juro']==='Fixo') {
                    $prices=$db->table('cis_tabela_juros')
                        ->where('limite_inferior','<=',$d['montante'])
                        ->where('limite_superior','>=',$d['montante'])
                        ->where('periodo_referencia',$d['periodo_dias'])->get();
                    abort_unless($prices->count()===1,422,
                        'Não foi encontrada uma taxa única na tabela de preços para o período seleccionado.');
                    $rate=(float)$prices[0]->taxa;
                    if (abs($rate-(float)$d['taxa_informada'])>0.00001) {
                        throw ValidationException::withMessages(['taxa_informada'=>
                            'A taxa mudou nas Definições. Actualize o formulário antes de continuar.']);
                    }
                    $band=(string)$prices[0]->limite_inferior.' - '.(string)$prices[0]->limite_superior;
                }
                $plan=LegacyContractPricing::plan((float)$d['montante'],$rate,
                    $d['tipo_juro'],$d['modalidade'],(int)$d['prazo_meses'],$d['data_inicio'],
                    $d['estrategia_calculo'],$d['prazo'],(int)$d['periodo_dias']);
                // Saldo da CONTA escolhida (VBA usava o agregado do Extracto).
                // Data passada: saldo da conta nesse dia, sem deixar nenhum dia seguinte negativo.
                if ($d['data_inicio'] !== now('Africa/Maputo')->toDateString()) {
                    CashAccountLedger::requireDateOpen($account,$d['data_inicio'],'registar o desembolso');
                    [$atDate,$available]=CashAccountLedger::availableOn($db,$account,$d['data_inicio']);
                    abort_if($available<(int)$d['montante']*100,422,
                        'Saldo insuficiente na conta '.$account->code.' em '
                        .implode('/',array_reverse(explode('-',$d['data_inicio']))).': '
                        .CashAccountLedger::amount($atDate).' MT nesse dia'
                        .($available<$atDate?', mas só '.CashAccountLedger::amount($available).' MT podem sair sem deixar a conta negativa nos dias seguintes':'')
                        .'.');
                } else {
                    abort_if($accountBalance<(int)$d['montante']*100,422,
                        'Saldo insuficiente na conta '.$account->code.' ('
                        .CashAccountLedger::amount($accountBalance).' MT) para este desembolso.');
                }

                // Evitar repetição por duplo clique/reenvio para a mesma
                // combinação. Revisão manual caso haja outro crédito legítimo igual.
                $dup=$db->table('Contractos')
                    ->where('Nº de Cliente',$d['cliente_id'])
                    ->where('Data de Inicio',$d['data_inicio'])
                    ->whereBetween('Quantia Creditada',[(float)$d['montante']-0.01,(float)$d['montante']+0.01])
                    ->exists();
                abort_if($dup,409,'Já existe contrato com mesmo mutuário, data e montante. Verifique a carteira.');

                $max=(int)$db->table('Contractos')->max('Nº');
                $sequence=$max+1;
                abort_if($sequence>999999999,409,'Limite de numeração atingido.');
                // Numeração anual (PREFIXO-ANO-SEQUÊNCIA) depois da renumeração; antes disso, C-0000.
                $number=\App\Services\ContractNumbering::active()
                    ? \App\Services\ContractNumbering::next($db,$d['data_inicio'])
                    : 'C-'.str_pad((string)$sequence,4,'0',STR_PAD_LEFT);
                abort_if($db->table('Contractos')->where('Nr do Contracto',$number)->exists(),
                    409,'Número de contrato já utilizado; operação cancelada.');

                $actor=mb_substr((string)$user->name,0,255);
                foreach ($plan['prestacoes'] as $row) {
                    $contractAttributes = [
                        'Nr do Contracto'=>$number,'Nº'=>$sequence,
                        'Nome do Cliente'=>$buyer->{'Nome Completo'},
                        'Nº de Cliente'=>$buyer->ID,'Quantia Creditada'=>$d['montante'],
                        'M. Pagamento'=>$plan['modalidade'],
                        'Estratégia de Cálculo'=>$d['estrategia_calculo'],
                        'Prazo/Duração'=>$d['prazo'],
                        '% de Juro'=>$rate,'Tipo de Juro'=>$d['tipo_juro'],
                        '% de Multa'=>$d['taxa_multa'],
                        'Forma de Pagamento'=>$d['forma_pagamento'],
                        'Disponibilidade'=>$d['disponibilidade'],
                        'Data de Inicio'=>$d['data_inicio'],
                        'Cidade'=>$buyer->Cidade,
                        'Data Final'=>$plan['data_final'],
                        'Data de Cobrança'=>$row['vencimento'],
                        'Capital'=>$row['capital'],
                        'Amortização'=>$row['amortizacao'],
                        'Valor a Pagar'=>$row['pagar'],
                        'Data de Pagamento'=>null,
                        'Valor de Juros'=>$row['juros'],
                        'Valor de Multa'=>0,'Valor Pago'=>0,
                        'Estado'=>'Pendente','Codigo Cliente'=>$buyer->ID,
                        'Estado do Contrato'=>'Em Progresso',
                        'Nº da Parcela'=>$row['parcela'],
                        'Nº de Prestações'=>count($plan['prestacoes']),
                        'Column1'=>$d['modalidade'],'Dias'=>0,'Intervalo'=>$band,
                        'Situação de Pagamento'=>'Em Progresso','usuario'=>$actor,
                    ];
                    // Query Builder divide nomes com ponto em tabela.coluna:
                    // `M. Pagamento` deve ser citado como identificador único.
                    [$insertSql, $insertBindings] = LegacyContractInsert::statement($contractAttributes);
                    $db->insert($insertSql, $insertBindings);
                }
                $nr=$db->table('Extracto')->insertGetId([
                    'Contracto'=>$number,'Cliente'=>$buyer->{'Nome Completo'},
                    'Parcela'=>0,'Data da Operação'=>$d['data_inicio'],
                    'Valor'=>CashAccountLedger::amount((int)$d['montante']*100),'Tipo de Movimento'=>'Saídas',
                    'Descrição'=>mb_substr('[CAIXA:DESEMBOLSO] Desembolso de Empréstimo '.$number.' | REF:'.$ref,0,255),
                    'usuario'=>$actor,'account_id'=>(int)$account->id,'operation_key'=>$key,
                ],'Nr');
                // Taxa de operação (Definições › Encargos do crédito): descontada no desembolso, entra como receita na mesma conta.
                $feeC=\App\Support\CreditCharges::operationFee((int)$d['montante']*100,true);
                if ($feeC>0) {
                    $db->table('Extracto')->insertGetId([
                        'Contracto'=>$number,'Cliente'=>$buyer->{'Nome Completo'},
                        'Parcela'=>0,'Data da Operação'=>$d['data_inicio'],
                        'Valor'=>CashAccountLedger::amount($feeC),'Tipo de Movimento'=>'Entradas',
                        'Descrição'=>mb_substr('[CAIXA:TAXA] Taxa de operação '.$number.' | REF:'.$ref,0,255),
                        'usuario'=>$actor,'account_id'=>(int)$account->id,'operation_key'=>$key.':taxa',
                    ],'Nr');
                }
                $created=$db->table('Contractos')->where('Nr do Contracto',$number)->orderBy('Ordem')->get();
                abort_unless(
                    $created->count()===count($plan['prestacoes'])
                    && $db->table('Extracto')->where('Contracto',$number)
                        ->where('operation_key',$key)->count()===1,
                    409,'Falha na verificação transaccional; alterações revertidas.'
                );
                foreach ($created as $row) {
                    ContractHistory::record($db,[
                        'contract_number'=>$number,'installment_ordem'=>(int)$row->Ordem,
                        'installment_number'=>(int)$row->{'Nº da Parcela'},'operation'=>'desembolso',
                        'before'=>null,'after'=>$row,'extracto_nr'=>$nr,'account_id'=>(int)$account->id,
                        'amount'=>CashAccountLedger::amount((int)$d['montante']*100),
                        'evidence_reference'=>$ref,'reason'=>'Abertura de contrato com desembolso.',
                        'operation_key'=>$key,'performed_by'=>(int)$user->id,
                    ]);
                }
                return ['numero'=>$number,'prestacoes'=>count($plan['prestacoes']),
                    'desembolso'=>$d['montante'],'data_inicio'=>$d['data_inicio'],
                    'estado'=>'Em Progresso','repetido'=>false,'extracto_nr'=>(int)$nr,
                    'conta'=>$account->code,'taxa_operacao'=>CashAccountLedger::amount($feeC),
                    'valor_entregue'=>CashAccountLedger::amount((int)$d['montante']*100-$feeC),
                    'saldo_conta_actual'=>CashAccountLedger::amount($accountBalance-(int)$d['montante']*100+$feeC)];
            }, 1);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            abort(503,'A base de dados recusou o desembolso (MySQL '.($e->errorInfo[1]??'?').'). Nada foi gravado. '
                .'Verifique as permissões com: bash deploy/verify-contract-cash-flows.sh');
        } finally {
            $db->select('SELECT RELEASE_LOCK(?)', ['cis_open_new_contract']);
            if ($proposalReserved && !isset($result)) ProposalController::release($proposalId);
        }
        if ($proposalReserved && empty($result['repetido']) && !empty($result['numero'])) {
            ProposalController::link($proposalId,(string)$result['numero'],(int)$user->id);
        } elseif ($proposalReserved) {
            ProposalController::release($proposalId);
        }
        // Agência do contrato: a do utilizador; quem vê todas pode indicar «agencia_id»; senão, a principal.
        try {
            if (\App\Support\Branches::ready() && empty($result['repetido']) && !empty($result['numero'])) {
                $branch = \App\Support\Branches::userBranch($user)
                    ?? (is_numeric($request->input('agencia_id')) && \Illuminate\Support\Facades\DB::table('cis_branches')->where('id', (int) $request->input('agencia_id'))->exists() ? (int) $request->input('agencia_id') : null)
                    ?? \App\Support\Branches::principalId();
                if ($branch) \App\Support\Branches::assignContract((string) $result['numero'], $branch, (int) $user->id);
            }
        } catch (\Throwable $e) { report($e); }
        return response()->json($result,201)->header('Cache-Control','no-store, private');
    }
}
