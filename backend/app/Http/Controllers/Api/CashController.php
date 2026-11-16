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
        } catch (\\Illuminate\\Database\\UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['code'=>'Código já utilizado por outra conta.']);
        }
        return response()->json([
            'id'=>$id,'status'=>'em_preparacao',
            'notice'=>'Conta cadastrada SEM saldo e SEM permissão para operações financeiras.',
        ],201)->header('Cache-Control','no-store, private');
    }

    // Abertura e activação de saldos requerem validação documental
    // independente e segregação de funções ainda em desenvolvimento.
    // A opção de cadastrar contas NÃO activa estes endpoints.
    public function submitOpening(Request $request, int $id)
    {
        $this->access($request);
        abort(503,'Abertura de saldo ainda não homologada. A conta continua em preparação.');
    }

    public function activate(Request $request, int $id)
    {
        $this->access($request);
        abort(503,'Activação financeira de contas ainda não homologada.');
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
