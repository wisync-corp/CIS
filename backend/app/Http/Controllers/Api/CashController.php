<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class CashController extends Controller
{
    private function access(Request $request, bool $write = false): void
    {
        $user = $request->user();
        abort_unless($user && $user->active && $user->role === 'administrador', 403,
            'Nesta fase, a Caixa está reservada ao administrador até activar a matriz de permissões.');
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

    private function accountRows()
    {
        return DB::table('cis_cash_accounts')->orderBy('code')->get()->map(function ($account) {
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
            ];
        });
    }

    public function index(Request $request)
    {
        $this->access($request);
        $accounts = $this->accountRows();
        return response()->json([
            'accounts' => $accounts,
            'can_write' => false, // Movimentações financeiras desactivadas.
            'can_create_accounts' => filter_var(env('CIS_CASH_ACCOUNT_SETUP_APPROVED',false),FILTER_VALIDATE_BOOLEAN),
            'can_submit_opening' => filter_var(env('CIS_CASH_OPENING_SETUP_APPROVED',false),FILTER_VALIDATE_BOOLEAN),
            'can_validate_opening' => filter_var(env('CIS_CASH_OPENING_VALIDATE_APPROVED',false),FILTER_VALIDATE_BOOLEAN),
            'notice' => 'Contas CIS; a fonte única é Extracto, com account_id opcional para preservar históricos. Escritas aguardam integração segura.'
        ])->header('Cache-Control', 'no-store, private');
    }

    public function movements(Request $request)
    {
        $this->access($request);
        $d = $request->validate(['account_id'=>['nullable','integer','min:1']]);
        $query=DB::table('Extracto')->whereNotNull('account_id')->orderByDesc('Nr');
        if (isset($d['account_id'])) $query->where('account_id',$d['account_id']);
        $rows=$query->limit(200)->get([
            'Nr as id','Nr as extracto_nr','account_id',
            'Tipo de Movimento as legacy_type','Valor as amount',
            'Descrição as description','Data da Operação as effective_at',
            'Contracto as contract_number','Parcela as installment',
            'usuario as operator'
        ])->map(function ($movement) {
            $movement->type=$movement->legacy_type==='Entradas'?'entrada':'saida';
            $movement->category=$movement->description;
            $movement->reference='EXT-'.(string)$movement->extracto_nr;
            $movement->evidence_reference=null; // o Extracto não tem este campo.
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

    private function openingSetupAllowed(): void
    {
        abort_unless(filter_var(env('CIS_CASH_OPENING_SETUP_APPROVED',false),FILTER_VALIDATE_BOOLEAN),503,
            'A preparação do saldo inicial ainda não foi autorizada.');
        abort_unless(!filter_var(env('CIS_CASH_WRITES_APPROVED',false),FILTER_VALIDATE_BOOLEAN),503,
            'Preparação de saldos requer as movimentações gerais bloqueadas.');
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
        $this->openingSetupAllowed();
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
            'notice'=>'Proposta guardada. A conta permanece sem saldo disponível até validação independente.',
        ])->header('Cache-Control','no-store, private');
    }

    public function activate(Request $request, int $id)
    {
        $this->access($request);
        $this->openingSetupAllowed();
        abort_unless(filter_var(env('CIS_CASH_OPENING_VALIDATE_APPROVED',false),FILTER_VALIDATE_BOOLEAN),503,
            'Validação independente de saldos ainda não autorizada.');
        $request->validate(['confirmed_document'=>['required','accepted']]);
        $validator=$request->user();
        $writer=DB::connection('mysql_cash_opening_validate');
        abort_unless($writer->getDatabaseName()===DB::connection()->getDatabaseName(),503,
            'A ligação de validação aponta para outra base.');
        $account=DB::table('cis_cash_accounts')->where('id',$id)->first();
        abort_unless($account && $account->status==='pendente_validacao',409,
            'Só pode validar uma proposta pendente.');
        abort_unless((int)$account->created_by!==(int)$validator->id,403,
            'Quem cadastrou a conta não pode aprovar o próprio saldo.');
        abort_unless($account->opening_balance!==null && $account->cut_at!==null
            && $account->evidence_reference!==null
            && trim((string)$account->evidence_reference)!=='',409,
            'A proposta não possui saldo, data de corte e comprovativo completos.');
        abort_unless(!DB::table('Extracto')->where('account_id',$id)->exists(),409,
            'Há movimentos associados: bloqueada a validação de abertura.');
        // O marcador é capturado no momento da validação; nenhuma escrituração
        // vinculada a contas é permitida enquanto esta fase estiver activa.
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
            'notice'=>'Saldo inicial validado por segundo responsável. Movimentações continuam bloqueadas.',
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
