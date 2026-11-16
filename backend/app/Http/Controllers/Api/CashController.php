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
        abort_unless(Schema::hasTable('cis_cash_accounts') && Schema::hasTable('cis_cash_links') && Schema::hasTable('Extracto'),
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
        // Fonte financeira única: Extracto. A ligação não duplica o valor.
        // Linhas históricas associadas para consulta não entram no saldo pós-abertura.
        $movements = DB::table('cis_cash_links as l')
            ->join('Extracto as e','e.Nr','=','l.extracto_nr')
            ->where('l.account_id',$account->id)
            ->where('l.counts_after_opening',true)
            ->get(['e.Tipo de Movimento as movement_type','e.Valor as amount']);
        foreach ($movements as $movement) {
            $value = $this->cents((string)$movement->amount);
            abort_unless($value>=0,409,'Extracto contém montante inválido.');
            abort_unless(in_array($movement->movement_type,['Entradas','Saídas'],true),409,
                'O Extracto contém um tipo não reconhecido; saldo indisponível.');
            $saldo += $movement->movement_type==='Entradas' ? $value : -$value;
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
            'can_write' => false, // Até integrar Extracto e associação de modo atómico.
            'notice' => 'Contas CIS; o Extracto permanece fonte única para movimentos financeiros. Escritas pendentes da integração híbrida segura.'
        ])->header('Cache-Control', 'no-store, private');
    }

    public function movements(Request $request)
    {
        $this->access($request);
        $d = $request->validate(['account_id'=>['nullable','integer','min:1']]);
        $query=DB::table('cis_cash_links as l')
            ->join('Extracto as e','e.Nr','=','l.extracto_nr')
            ->orderByDesc('l.id');
        if (isset($d['account_id'])) $query->where('l.account_id',$d['account_id']);
        $rows=$query->limit(200)->get([
            'l.id','l.account_id','l.nature as category','l.evidence_reference',
            'e.Nr as extracto_nr','e.Tipo de Movimento as legacy_type',
            'e.Valor as amount','e.Descrição as description',
            'e.Data da Operação as effective_at','l.idempotency_key as reference',
            'l.confirmation','l.confirmed_at','l.counts_after_opening'
        ])->map(function ($movement) {
            $movement->type=$movement->legacy_type==='Entradas'?'entrada':'saida';
            return $movement;
        });
        return response()->json([
            'movements'=>$rows,
            'notice'=>'Movimentos do Extracto associados a contas; valores não duplicados. Importação e reconciliação de extractos bancários reservadas à Tesouraria.'
        ])->header('Cache-Control','no-store, private');
    }

    public function createAccount(Request $request)
    {
        $this->access($request, true);
        $d = $request->validate([
            'code'=>['required','string','regex:/^[A-Za-z0-9_-]{2,40}$/','unique:cis_cash_accounts,code'],
            'name'=>['required','string','min:3','max:150'],
            'type'=>['required','in:banco,numerario,carteira_movel'],
            'identifier'=>['nullable','string','max:120'],
        ]);
        $id = DB::table('cis_cash_accounts')->insertGetId([
            'code'=>strtoupper($d['code']), 'name'=>$d['name'], 'type'=>$d['type'],
            'identifier'=>$d['identifier']??null, 'currency'=>'MZN',
            'status'=>'em_preparacao','created_by'=>$request->user()->id,
            'created_at'=>now(), 'updated_at'=>now(),
        ]);
        return response()->json(['id'=>$id,'status'=>'em_preparacao'],201);
    }

    public function submitOpening(Request $request, int $id)
    {
        $this->access($request, true);
        $d=$request->validate([
            'opening_balance'=>['required','string','max:20'],
            'cut_at'=>['required','date_format:Y-m-d H:i:s'],
            'evidence_reference'=>['required','string','min:5','max:255'],
        ]);
        $amount = $this->cents($d['opening_balance']);
        abort_if($amount < 0, 422);
        $cut = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $d['cut_at'], 'Africa/Maputo');
        abort_if($cut->greaterThan(now('Africa/Maputo')), 422, 'Data de corte futura não permitida.');
        DB::transaction(function () use ($d,$id,$request,$amount) {
            $account=DB::table('cis_cash_accounts')->where('id',$id)->lockForUpdate()->first();
            abort_if(!$account,404,'Conta não encontrada.');
            abort_unless($account->status==='em_preparacao',409,'Conta já submetida ou activa.');
            abort_if(DB::table('cis_cash_links')->where('account_id',$id)->exists(),409,
                'A conta já tem movimentos; abertura bloqueada.');
            DB::table('cis_cash_accounts')->where('id',$id)->update([
                'opening_balance'=>$this->decimal($amount), 'cut_at'=>$d['cut_at'],
                'evidence_reference'=>$d['evidence_reference'],
                'status'=>'pendente_validacao', 'updated_at'=>now(),
            ]);
        });
        return response()->json(['status'=>'pendente_validacao']);
    }

    public function activate(Request $request, int $id)
    {
        $this->access($request,true);
        $d=$request->validate([
            'confirmed_document'=>['required','accepted'],
        ]);
        DB::transaction(function () use ($id,$request) {
            $account=DB::table('cis_cash_accounts')->where('id',$id)->lockForUpdate()->first();
            abort_if(!$account,404,'Conta não encontrada.');
            abort_unless($account->status==='pendente_validacao'
                && $account->opening_balance!==null && $account->cut_at!==null
                && filled($account->evidence_reference),409,'A conta não está pronta para validar.');
            DB::table('cis_cash_accounts')->where('id',$id)->update([
                'status'=>'activa', 'validated_by'=>$request->user()->id,
                'validated_at'=>now(),'updated_at'=>now(),
            ]);
        });
        return response()->json(['status'=>'activa',
            'notice'=>'Validação documental manual; não representa conciliação bancária independente.']);
    }

    // NÃO efectuar escrituras financeiras pelo antigo fluxo independente:
    // o modelo híbrido exige inserir uma linha em Extracto e associar o seu Nr
    // a uma conta na MESMA transacção. Não activar sem conexão e privilégios
    // transaccionais validados, regras de aprovação e idempotência.
    public function record(Request $request)
    {
        $this->access($request);
        abort(503,'Registo de movimentos em preparação para o modelo híbrido com Extracto; nenhum lançamento foi efectuado.');
    }

    public function transfer(Request $request)
    {
        $this->access($request);
        abort(503,'Transferências em preparação para associação atómica de duas linhas do Extracto; nenhum lançamento foi efectuado.');
    }
}
