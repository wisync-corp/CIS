<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LegacyContractPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $user = $request->user();
        abort_unless($user && $user->active && $user->role === 'administrador', 403);
        foreach (['CIS_PRIVATE_PREVIEW','CIS_CLIENTES_PREVIEW_APPROVED',
            'CIS_CONTRACTS_READ_APPROVED','CIS_NEW_CONTRACTS_APPROVED'] as $flag) {
            abort_unless(filter_var(env($flag,false), FILTER_VALIDATE_BOOLEAN), 503,
                'Abertura real ainda não activada neste ambiente privado.');
        }

        $d = $request->validate([
            'cliente_id'=>['required','integer','min:1'],
            'montante'=>['required','numeric','gt:0','max:100000000','decimal:0,2'],
            'tipo_juro'=>['required','in:Fixo,Variável,Composto'],
            'taxa_informada'=>['required','numeric','between:0,100'],
            'taxa_multa'=>['required','numeric','between:0,100'],
            'prazo_meses'=>['required','integer','in:1,2,3'],
            'modalidade'=>['required','in:Mensal,Uma Parcela'],
            'forma_pagamento'=>['required','in:Transferência/Depósito Bancário,Transferência Móvel,Numerário'],
            'disponibilidade'=>['required','in:Transferência/Depósito Bancário,Transferência Móvel,Numerário'],
            'data_inicio'=>['required','date_format:Y-m-d'],
            'confirmacao_desembolso'=>['required','accepted'],
        ]);
        // Esquema antigo: juros/multa INT. Não truncar percentagens silenciosamente.
        foreach (['taxa_informada','taxa_multa'] as $taxa) {
            if (abs((float)$d[$taxa] - round((float)$d[$taxa])) > 0.000001) {
                throw ValidationException::withMessages([$taxa=>
                    'A coluna financeira original é INT e não permite taxas com casas decimais.']);
            }
        }
        // A data da operação é a data efectiva de desembolso, nunca uma
        // data histórica inserida retroactivamente ou futura.
        if ($d['data_inicio'] !== now('Africa/Maputo')->toDateString()) {
            throw ValidationException::withMessages(['data_inicio'=>
                'Para desembolso real, a data de início deve ser a data de hoje em Moçambique.']);
        }
        if ($d['tipo_juro']==='Variável' && $d['modalidade']!=='Uma Parcela' ||
            $d['tipo_juro']==='Composto' && $d['modalidade']!=='Mensal') {
            throw ValidationException::withMessages(['modalidade'=>'Modalidade incompatível com o tipo de juro.']);
        }

        $db = DB::connection('mysql_contract_writer');
        $lock = $db->selectOne('SELECT GET_LOCK(?, 10) AS acquired', ['cis_open_new_contract']);
        abort_unless($lock && (int)$lock->acquired === 1, 409,
            'Outra abertura está a decorrer. Aguarde e consulte a carteira antes de repetir.');
        try {
            $result = $db->transaction(function () use ($db,$d,$user) {
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
                foreach (['Contractos'=>['Ordem','Nr do Contracto','Nº',
                    'Nome do Cliente','Nº de Cliente','Quantia Creditada','M. Pagamento',
                    'Prazo/Duração','% de Juro','Tipo de Juro','% de Multa',
                    'Forma de Pagamento','Disponibilidade','Data de Inicio',
                    'Cidade','Data Final','Data de Cobrança','Capital',
                    'Amortização','Valor a Pagar','Data de Pagamento','Valor de Juros',
                    'Valor de Multa','Valor Pago','Estado','Codigo Cliente',
                    'Estado do Contrato','Nº da Parcela','Nº de Prestações',
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
                    ->lockForUpdate()->limit(2)
                    ->get(['ID','Nome Completo','Cidade']);
                abort_if($buyer->count()!==1,422,
                    'Cliente inexistente ou identificador duplicado.');
                $buyer = $buyer[0];
                abort_if(!trim((string)$buyer->{'Nome Completo'}),422,'Cliente sem nome.');

                $rate=(float)$d['taxa_informada'];
                $band='';
                if ($d['tipo_juro']==='Fixo') {
                    $prices=$db->table('cis_tabela_juros')
                        ->where('limite_inferior','<=',$d['montante'])
                        ->where('limite_superior','>=',$d['montante'])
                        ->where('periodo_referencia',30)->get();
                    abort_unless($prices->count()===1,422,
                        'Não foi encontrada uma taxa única na tabela de preços para 30 dias.');
                    $rate=(float)$prices[0]->taxa;
                    if (abs($rate-(float)$d['taxa_informada'])>0.00001) {
                        throw ValidationException::withMessages(['taxa_informada'=>
                            'A taxa mudou nas Definições. Actualize o formulário antes de continuar.']);
                    }
                    $band=(string)$prices[0]->limite_inferior.' - '.(string)$prices[0]->limite_superior;
                }
                $plan=LegacyContractPricing::plan((float)$d['montante'],$rate,
                    $d['tipo_juro'],$d['modalidade'],(int)$d['prazo_meses'],$d['data_inicio']);
                $cashRows=$db->table('Extracto')
                    ->select(['Tipo de Movimento','Valor'])->lockForUpdate()->get();
                $saldo=0.0;
                foreach ($cashRows as $movement) {
                    $amount=(float)$movement->Valor;
                    abort_unless(is_finite($amount) && $amount>=0,409,
                        'Extracto contém valor inválido; desembolso suspenso.');
                    $saldo += $movement->{'Tipo de Movimento'}==='Entradas'?$amount:-$amount;
                }
                // VBA CalculateBalance: RoundDown(..., 0) antes da validação.
                $saldoDisponivel= floor($saldo);
                abort_if($saldoDisponivel<(float)$d['montante'],422,
                    'Saldo empresarial insuficiente para o desembolso solicitado.');

                // Evitar repetição por duplo clique/reenvio para a mesma
                // combinação. Revisão manual caso haja outro crédito legítimo igual.
                $dup=$db->table('Contractos')
                    ->where('Nº de Cliente',$d['cliente_id'])
                    ->where('Data de Inicio',$d['data_inicio'])
                    ->where('Quantia Creditada',$d['montante'])
                    ->exists();
                abort_if($dup,409,'Já existe contrato com mesmo cliente, data e montante. Verifique a carteira.');

                $max=(int)$db->table('Contractos')->max('Nº');
                $sequence=$max+1;
                abort_if($sequence>999999999,409,'Limite de numeração atingido.');
                $number='C-'.str_pad((string)$sequence,4,'0',STR_PAD_LEFT);
                abort_if($db->table('Contractos')->where('Nr do Contracto',$number)->exists(),
                    409,'Número de contrato já utilizado; operação cancelada.');

                $actor=mb_substr((string)$user->name,0,255);
                foreach ($plan['prestacoes'] as $row) {
                    $db->table('Contractos')->insert([
                        'Nr do Contracto'=>$number,'Nº'=>$sequence,
                        'Nome do Cliente'=>$buyer->{'Nome Completo'},
                        'Nº de Cliente'=>$buyer->ID,'Quantia Creditada'=>$d['montante'],
                        'M. Pagamento'=>$plan['modalidade'],
                        'Prazo/Duração'=>$d['prazo_meses'].' '.((int)$d['prazo_meses']===1?'Mês':'Meses'),
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
                        'Column1'=>'Mensal','Dias'=>0,'Intervalo'=>$band,
                        'Situação de Pagamento'=>'Em Progresso','usuario'=>$actor,
                    ]);
                }
                $db->table('Extracto')->insert([
                    'Contracto'=>$number,'Cliente'=>$buyer->{'Nome Completo'},
                    'Parcela'=>0,'Data da Operação'=>$d['data_inicio'],
                    'Valor'=>$d['montante'],'Tipo de Movimento'=>'Saídas',
                    'Descrição'=>'Desembolso de Empréstimo','usuario'=>$actor,
                ]);
                abort_unless(
                    $db->table('Contractos')->where('Nr do Contracto',$number)->count()
                        ===count($plan['prestacoes'])
                    && $db->table('Extracto')->where('Contracto',$number)
                        ->where('Descrição','Desembolso de Empréstimo')->count()===1,
                    409,'Falha na verificação transaccional; alterações revertidas.'
                );
                return ['numero'=>$number,'prestacoes'=>count($plan['prestacoes']),
                    'desembolso'=>$d['montante'],'data_inicio'=>$d['data_inicio'],
                    'estado'=>'Em Progresso'];
            }, 1);
        } finally {
            $db->select('SELECT RELEASE_LOCK(?)', ['cis_open_new_contract']);
        }
        return response()->json($result,201)->header('Cache-Control','no-store, private');
    }
}
