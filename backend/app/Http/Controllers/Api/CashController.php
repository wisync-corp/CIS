<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class CashController extends Controller
{
    private function access(Request $request, bool $write = false, array $perms = ['caixa']): void
    {
        $user = $request->user();
        \App\Support\Permissions::require($user, $perms);
        abort_unless(filter_var(env('CIS_PRIVATE_PREVIEW', false), FILTER_VALIDATE_BOOLEAN), 503);
        abort_unless(Schema::hasTable('cis_cash_accounts') && Schema::hasTable('Extracto') && Schema::hasColumn('Extracto','account_id'),
            503, 'O esquema da Caixa ainda não foi instalado.');
        if ($write) {
            abort_unless(filter_var(env('CIS_CASH_WRITES_APPROVED', false), FILTER_VALIDATE_BOOLEAN), 503,
                'Operações de Caixa não activadas neste ambiente. É necessária homologação financeira.');
        }
    }

    private function cents(string $amount): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,14})(?:\.([0-9]{1,2}))?$/', $amount)) {
            throw ValidationException::withMessages(['amount' => 'Montante inválido: use MZN com até duas casas decimais.']);
        }
        [$units, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return (int)$units * 100 + (int)str_pad($fraction, 2, '0');
    }

    private function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function balance(object $account): int
    {
        abort_unless($account->opening_balance !== null && $account->cut_at !== null, 409,
            'A conta ainda não tem saldo inicial documentado e validado.');
        $saldo = $this->cents((string)$account->opening_balance);
        abort_unless($account->opening_extracto_nr !== null,409,
            'A conta não possui marcador de abertura homologado.');
        // Apenas linhas posteriores ao marco de abertura de Extracto,
        // atribuídas directamente a esta conta, entram neste saldo.
        // Nunca inferir conta a partir de Contracto ou Descrição.
        $movements=DB::table('Extracto')
            ->where('account_id',$account->id)
            ->where('Nr','>',$account->opening_extracto_nr)
            ->get(['Tipo de Movimento','Valor']);
        foreach ($movements as $movement) {
            $value=$this->cents((string)$movement->Valor);
            abort_unless(in_array($movement->{'Tipo de Movimento'},['Entradas','Saídas'],true),409,
                'Tipo de movimento desconhecido no Extracto.');
            $saldo += $movement->{'Tipo de Movimento'}==='Entradas' ? $value : -$value;
        }
        return $saldo;
    }

    private function accountRows(int $userId)
    {
        return DB::table('cis_cash_accounts')->orderBy('code')->get()->map(function ($account) use ($userId) {
            $available = $account->status === 'activa' ? $this->balance($account) : null;
            return [
                'id' => $account->id, 'code' => $account->code,
                'name' => $account->name, 'type' => $account->type,
                'identifier' => $account->identifier, 'currency' => $account->currency,
                'status' => $account->status, 'cut_at' => $account->cut_at,
                'opening_balance' => $account->opening_balance,
                'evidence_reference' => $account->evidence_reference,
                'saldo_disponivel' => $available === null ? null : $this->decimal($available),
                'created_by' => $account->created_by,
                'validated_by' => $account->validated_by,
                'validated_at' => $account->validated_at,
                'can_prepare' => $account->status==='em_preparacao'
                    && (int)$account->created_by===$userId,
                'can_validate' => $account->status==='pendente_validacao'
                    && ($this->singleAdminOpening() || (int)$account->created_by!==$userId),
            ];
        });
    }

    public function index(Request $request)
    {
        // Lista de contas também para quem recebe pagamentos, abre contratos ou vê extractos.
        $this->access($request, false, ['caixa','cobrancas','contratos.abrir','extractos']);
        $accounts = $this->accountRows((int)$request->user()->id);
        return response()->json([
            'accounts' => $accounts,
            'can_write' => filter_var(env('CIS_CASH_WRITES_APPROVED',false),FILTER_VALIDATE_BOOLEAN)
                && filter_var(env('CIS_CASH_LEDGER_POSTING_APPROVED',false),FILTER_VALIDATE_BOOLEAN)
                && Schema::hasColumn('Extracto','operation_key'), // Nunca activar só com a coluna.
            'can_create_accounts' => filter_var(env('CIS_CASH_ACCOUNT_SETUP_APPROVED',false),FILTER_VALIDATE_BOOLEAN),
            'can_submit_opening' => $this->openingSetupApproved(),
            'can_validate_opening' => $this->openingValidationApproved(),
            'opening_blocked_reason' => null,
            'single_admin_opening' => $this->singleAdminOpening(),
            'notice' => 'Fonte única de movimentos: Extracto; contas identificadas por account_id. A escrita real depende de autorização operacional adicional.'
        ])->header('Cache-Control', 'no-store, private');
    }

    public function movements(Request $request)
    {
        $this->access($request);
        $d = $request->validate(['account_id'=>['nullable','integer','min:1']]);
        $query=DB::table('Extracto')->whereNotNull('account_id')->orderByDesc('Nr');
        if (isset($d['account_id'])) $query->where('account_id',$d['account_id']);
        $columns=[
            'Nr as id','Nr as extracto_nr','account_id',
            'Tipo de Movimento as legacy_type','Valor as amount',
            'Descrição as description','Data da Operação as effective_at',
            'Contracto as contract_number','Parcela as installment',
            'usuario as operator'
        ];
        if (Schema::hasColumn('Extracto','operation_key')) {
            $columns[]='operation_key';
        }
        $rows=$query->limit(200)->get($columns)->map(function ($movement) {
            $isReversal=isset($movement->operation_key)
                && str_starts_with((string)$movement->operation_key,'cash:reverse:');
            $isTransfer=isset($movement->operation_key)
                && (preg_match('/^\\[TRANSFER (SAIDA|ENTRADA) /',$movement->description)===1
                    || preg_match('/^\\[ESTORNO TRANSFER ORIG:/',$movement->description)===1);
            $entry=$movement->legacy_type==='Entradas';
            $movement->type=$isReversal
                ?($entry?'estorno_entrada':'estorno_saida')
                :($isTransfer?($entry?'transferencia_entrada':'transferencia_saida')
                    :($entry?'entrada':'saida'));
            $movement->category=$isReversal
                ?($isTransfer?'Estorno de transferência':'Estorno de movimento')
                :($isTransfer?'Transferência interna':'Operação geral');
            if (!$isTransfer && preg_match('/^\\[CAIXA:([^]]+)\\]/u',
                $movement->description,$match)) {
                $movement->category=$match[1];
            }
            $movement->reference='EXT-'.(string)$movement->extracto_nr;
            $movement->evidence_reference=null;
            if (isset($movement->operation_key)
                && preg_match('/ \\| REF:(.{6,70})$/u',
                    $movement->description,$match)) {
                $movement->evidence_reference=$match[1];
            }
            return $movement;
        });
        return response()->json([
            'movements'=>$rows,
            'notice'=>'Movimentos da tabela Extracto com account_id identificado; dados históricos sem conta não são atribuídos automaticamente.'
        ])->header('Cache-Control','no-store, private');
    }

    /**
     * Cadastro de CONTAS sem saldo, abertura ou movimentos.
     * A flag e o utilizador MySQL dedicado sao independentes da autorização
     * de saídas e desembolsos. Só INSERT em cis_cash_accounts.
     */
    public function createAccount(Request $request)
    {
        $this->access($request);
        abort_unless(filter_var(env('CIS_CASH_ACCOUNT_SETUP_APPROVED', false), FILTER_VALIDATE_BOOLEAN), 503,
            'Cadastro de contas ainda não activado. Operações financeiras permanecem bloqueadas.');
        $d=$request->validate([
            'code'=>['required','string','regex:/^[A-Za-z0-9_-]{2,40}$/'],
            'name'=>['required','string','min:3','max:150'],
            'type'=>['required','in:banco,numerario,carteira_movel'],
            'identifier'=>['nullable','string','max:120'],
        ]);
        $code=strtoupper($d['code']);
        $writer=DB::connection('mysql_cash_setup');
        abort_unless($writer->getDatabaseName() === DB::connection()->getDatabaseName(),503,
            'Ligação de cadastro aponta para uma base diferente da Caixa.');
        try {
            $id=$writer->table('cis_cash_accounts')->insertGetId([
                'code'=>$code,'name'=>$d['name'],'type'=>$d['type'],
                'identifier'=>$d['identifier']??null,'currency'=>'MZN',
                'status'=>'em_preparacao','created_by'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['code'=>'Código já utilizado por outra conta.']);
        }
        return response()->json([
            'id'=>$id,'status'=>'em_preparacao',
            'notice'=>'Conta cadastrada SEM saldo e SEM permissão para operações financeiras.',
        ],201)->header('Cache-Control','no-store, private');
    }

    private function singleAdminOpening(): bool
    {
        return filter_var(env('CIS_CASH_SINGLE_ADMIN_APPROVED',false), FILTER_VALIDATE_BOOLEAN);
    }

    private function openingSetupApproved(): bool
    {
        return filter_var(env('CIS_CASH_OPENING_SETUP_APPROVED', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function openingValidationApproved(): bool
    {
        return filter_var(env('CIS_CASH_OPENING_VALIDATE_APPROVED', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Duas identidades MySQL distintas:
     * cash_opening_submit só pode actualizar campos de proposta em contas;
     * cash_opening_validate só pode activar contas pendentes com os campos de validação.
     * Nenhuma destas ligações escreve em Extracto.
     */
    public function submitOpening(Request $request, int $id)
    {
        $this->access($request);
        abort_unless($this->openingSetupApproved(), 503,
            'A preparação do saldo inicial ainda não foi autorizada.');
        $request->merge(['evidence_reference' => is_string($request->input('evidence_reference'))
            ? trim($request->input('evidence_reference')) : $request->input('evidence_reference')]);
        $data=$request->validate([
            'opening_balance'=>['required','string','regex:/^(0|[1-9][0-9]{0,12})(?:\\.[0-9]{1,2})?$/'],
            'cut_at'=>['required','date_format:Y-m-d H:i:s'],
            'evidence_reference'=>['required','string','min:6','max:255'],
        ]);
        abort_if(\Carbon\CarbonImmutable::parse($data['cut_at'],'Africa/Maputo')
            ->greaterThan(\Carbon\CarbonImmutable::now('Africa/Maputo')),422,
            'A data de corte não pode estar no futuro.');
        $writer=DB::connection('mysql_cash_opening_submit');
        abort_unless($writer->getDatabaseName()===DB::connection()->getDatabaseName(),503,
            'A ligação de preparação aponta para outra base.');
        $account=DB::table('cis_cash_accounts')->where('id',$id)->first();
        abort_unless($account && $account->status==='em_preparacao',409,
            'Só é possível preparar a abertura de uma conta em preparação.');
        abort_unless((int)$account->created_by===(int)$request->user()->id,403,
            'A proposta deve ser preparada pelo responsável que cadastrou a conta.');
        abort_unless(!DB::table('Extracto')->where('account_id',$id)->exists(),409,
            'Esta conta já tem movimentos e não pode receber abertura nova.');
        $changed=$writer->table('cis_cash_accounts')
            ->where('id',$id)->where('status','em_preparacao')
            ->whereNull('validated_at')->whereNull('opening_extracto_nr')
            ->update([
                'opening_balance'=>$data['opening_balance'],
                'cut_at'=>$data['cut_at'],
                'evidence_reference'=>trim($data['evidence_reference']),
                'status'=>'pendente_validacao','updated_at'=>now(),
            ]);
        abort_unless($changed===1,409,'A conta mudou de estado; actualize antes de tentar novamente.');
        return response()->json([
            'status'=>'pendente_validacao',
            'notice'=>$this->singleAdminOpening()
                ?'Proposta guardada. Confirme separadamente o saldo real e o comprovativo antes de activar a conta.'
                :'Proposta guardada. A conta permanece sem saldo disponível até validação independente.',
        ])->header('Cache-Control','no-store, private');
    }

    public function activate(Request $request, int $id)
    {
        $this->access($request);
        abort_unless($this->openingValidationApproved(), 503,
            'Validação independente de saldos ainda não autorizada.');
        $request->validate([
            'confirmed_document'=>['required','accepted'],
            'confirmation_phrase'=>['required','string','in:CONFIRMO-SALDO-REAL'],
        ]);
        $validator=$request->user();
        $writer=DB::connection('mysql_cash_opening_validate');
        abort_unless($writer->getDatabaseName()===DB::connection()->getDatabaseName(),503,
            'A ligação de validação aponta para outra base.');
        $account=DB::table('cis_cash_accounts')->where('id',$id)->first();
        abort_unless($account && $account->status==='pendente_validacao',409,
            'Só pode validar uma proposta pendente.');
        abort_unless($this->singleAdminOpening() ||
            (int)$account->created_by!==(int)$validator->id,403,
            'Esta configuração exige um segundo aprovador.');
        abort_unless($account->opening_balance!==null && $account->cut_at!==null
            && $account->evidence_reference!==null
            && trim((string)$account->evidence_reference)!=='',409,
            'A proposta não possui saldo, data de corte e comprovativo completos.');
        abort_unless(!DB::table('Extracto')->where('account_id',$id)->exists(),409,
            'Há movimentos associados: bloqueada a validação de abertura.');
        // Os lançamentos exigem uma conta activa e bloqueiam a respectiva linha.
        // Enquanto estiver pendente, esta conta não recebe movimentos pelo CIS.
        // O marcador e a activação são gravados juntos na actualização condicional.
        $last=(int)(DB::table('Extracto')->max('Nr')??0);
        $changed=$writer->table('cis_cash_accounts')
            ->where('id',$id)->where('status','pendente_validacao')
            ->whereNull('validated_at')->whereNull('opening_extracto_nr')
            ->update([
                'opening_extracto_nr'=>$last,
                'validated_by'=>$validator->id,
                'validated_at'=>now(),
                'status'=>'activa','updated_at'=>now(),
            ]);
        abort_unless($changed===1,409,'A conta mudou de estado durante a validação.');
        return response()->json([
            'status'=>'activa','opening_extracto_nr'=>$last,
            'notice'=>$this->singleAdminOpening()
                ?'Saldo inicial confirmado pelo administrador único com evidência documental. Não houve validação independente.'
                :'Saldo inicial validado por segundo responsável.',
        ])->header('Cache-Control','no-store, private');
    }


    /**
     * Prévia do movimento, sem INSERT/UPDATE e sem reserva de saldo.
     * Não é um compromisso de execução: confirmar futuramente exigirá
     * nova verificação sob lock, idempotência persistida e autorização.
     */
    public function previewRecord(Request $request)
    {
        $this->access($request);
        $d=$request->validate([
            'account_id'=>['required','integer','min:1'],
            'type'=>['required','in:entrada,saida'],
            'amount'=>['required','string','regex:/^(?:0|[1-9][0-9]{0,12})(?:\\.[0-9]{1,2})?$/'],
        ]);
        $value=$this->cents($d['amount']);
        abort_unless($value>0,422,'O montante deve ser superior a zero.');
        $account=DB::table('cis_cash_accounts')->where('id',$d['account_id'])->first();
        abort_unless($account && $account->status==='activa' && $account->currency==='MZN',409,
            'Seleccione uma conta activa e em meticais.');
        $before=$this->balance($account);
        $after=$before+($d['type']==='entrada'?$value:-$value);
        abort_unless($after>=0,409,'Saldo insuficiente na conta seleccionada.');
        return response()->json([
            'consulta_apenas'=>true,'movimento_registado'=>false,'saldo_reservado'=>false,
            'type'=>$d['type'],'account_id'=>$account->id,
            'account_code'=>$account->code,'amount'=>$this->decimal($value),
            'saldo_actual'=>$this->decimal($before),
            'saldo_estimado'=>$this->decimal($after),
            'notice'=>'Prévia baseada nos movimentos actuais. Não executa entrada ou saída, não reserva saldo e não substitui comprovativo.',
        ])->header('Cache-Control','no-store, private');
    }

    /**
     * Prévia de transferência: a futura execução terá de produzir as duas
     * linhas na MESMA transacção, com prevenção de duplicações.
     */
    public function previewTransfer(Request $request)
    {
        $this->access($request);
        $d=$request->validate([
            'source_account_id'=>['required','integer','min:1'],
            'destination_account_id'=>['required','integer','min:1','different:source_account_id'],
            'amount'=>['required','string','regex:/^(?:0|[1-9][0-9]{0,12})(?:\\.[0-9]{1,2})?$/'],
        ]);
        $value=$this->cents($d['amount']);
        abort_unless($value>0,422,'O montante deve ser superior a zero.');
        $accounts=DB::table('cis_cash_accounts')
            ->whereIn('id',[$d['source_account_id'],$d['destination_account_id']])
            ->get()->keyBy('id');
        $source=$accounts->get($d['source_account_id']);
        $destination=$accounts->get($d['destination_account_id']);
        abort_unless($source && $destination
            && $source->status==='activa' && $destination->status==='activa'
            && $source->currency==='MZN' && $destination->currency==='MZN',409,
            'Ambas as contas devem estar activas e em meticais.');
        $sourceBefore=$this->balance($source);
        $destinationBefore=$this->balance($destination);
        abort_unless($sourceBefore >= $value,409,'Saldo insuficiente na conta de origem.');
        return response()->json([
            'consulta_apenas'=>true,'movimento_registado'=>false,'saldo_reservado'=>false,
            'amount'=>$this->decimal($value),
            'source'=>[
                'account_id'=>$source->id,'code'=>$source->code,
                'saldo_actual'=>$this->decimal($sourceBefore),
                'saldo_estimado'=>$this->decimal($sourceBefore-$value),
            ],
            'destination'=>[
                'account_id'=>$destination->id,'code'=>$destination->code,
                'saldo_actual'=>$this->decimal($destinationBefore),
                'saldo_estimado'=>$this->decimal($destinationBefore+$value),
            ],
            'notice'=>'Prévia sem transferência. São necessários comprovativos de origem e destino, idempotência e lançamento atómico para execução.',
        ])->header('Cache-Control','no-store, private');
    }

    // NÃO efectuar escrituras financeiras pelo antigo fluxo independente:
    // o modelo directo exige inserir a linha em Extracto com account_id
    // validado, sob lock de conta, na MESMA transacção da operação principal.
    // Não activar sem privilégios homologados, idempotência e regras de aprovação.
    public function record(Request $request)
    {
        $this->access($request);
        abort(503,'Registo de movimentos em preparação para Extracto.account_id; nenhum lançamento foi efectuado.');
    }

    public function transfer(Request $request)
    {
        $this->access($request);
        abort(503,'Transferências em preparação para duas linhas atómicas de Extracto.account_id; nenhum lançamento foi efectuado.');
    }
}
