<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CashController extends Controller
{
    private function access(Request $request, bool $write = false): void
    {
        $user = $request->user();
        abort_unless($user && $user->active && $user->role === 'administrador', 403,
            'Nesta fase, a Caixa está reservada ao administrador até activar a matriz de permissões.');
        abort_unless(filter_var(env('CIS_PRIVATE_PREVIEW', false), FILTER_VALIDATE_BOOLEAN), 503);
        abort_unless(Schema::hasTable('cis_cash_accounts') && Schema::hasTable('cis_cash_movements'),
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
            'A conta ainda não tem saldo inicial reconciliado.');
        $saldo = $this->cents((string)$account->opening_balance);
        $movements = DB::table('cis_cash_movements')
            ->where('account_id', $account->id)->where('status', 'confirmado')
            ->where('effective_at', '>', $account->cut_at)->get(['type','amount']);
        foreach ($movements as $movement) {
            $value = $this->cents((string)$movement->amount);
            $saldo += in_array($movement->type,
                ['entrada','transferencia_entrada','estorno_entrada'], true) ? $value : -$value;
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
            'can_write' => filter_var(env('CIS_CASH_WRITES_APPROVED', false), FILTER_VALIDATE_BOOLEAN),
            'notice' => 'Contas da Caixa CIS; saldos iniciais documentais, não reconciliados automaticamente com extractos bancários. Extracto legado separado.'
        ])->header('Cache-Control', 'no-store, private');
    }

    public function movements(Request $request)
    {
        $this->access($request);
        $d = $request->validate(['account_id'=>['nullable','integer','min:1']]);
        $query = DB::table('cis_cash_movements')->orderByDesc('id');
        if (isset($d['account_id'])) $query->where('account_id', $d['account_id']);
        $rows = $query->limit(200)->get();
        return response()->json(['movements'=>$rows,
            'notice'=>'Últimos 200 movimentos confirmados da Caixa CIS; não inclui Extracto legado.']
        )->header('Cache-Control', 'no-store, private');
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
            abort_if(DB::table('cis_cash_movements')->where('account_id',$id)->exists(),409,
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

    public function record(Request $request)
    {
        $this->access($request,true);
        $d=$request->validate([
            'account_id'=>['required','integer','min:1'],
            'type'=>['required','in:entrada,saida'],
            'amount'=>['required','string','max:20'],
            'category'=>['required','string','min:3','max:90'],
            'description'=>['required','string','min:4','max:500'],
            'evidence_reference'=>['nullable','string','max:255'],
            'idempotency_key'=>['required','uuid'],
        ]);
        $amount=$this->cents($d['amount']);
        abort_if($amount<=0,422,'O montante deve ser positivo.');
        abort_if(in_array(mb_strtolower(trim($d['category'])),
            ['desembolso de empréstimo','cobrança','prestação de crédito','desembolso'],true),
            422,'Movimentos de crédito devem ser lançados exclusivamente pelo fluxo integrado de contratos.');
        $reference='CIS-CAIXA-'.$d['idempotency_key'];
        DB::transaction(function () use ($d,$request,$amount,$reference) {
            abort_if(DB::table('cis_cash_movements')->where('reference',$reference)->exists(),409,
                'Esta operação já foi processada. Actualize o histórico antes de repetir.');
            $account=DB::table('cis_cash_accounts')->where('id',$d['account_id'])->lockForUpdate()->first();
            abort_if(!$account,404,'Conta não encontrada.');
            abort_unless($account->status==='activa' && $account->currency==='MZN',409,'Conta não activa ou moeda incompatível.');
            if ($d['type']==='saida') {
                abort_if($this->balance($account)<$amount,422,'Saldo disponível insuficiente nesta conta.');
                if ($account->type!=='numerario') {
                    abort_unless(filled($d['evidence_reference']??null),422,
                        'Referência ou comprovativo obrigatório para saída não numerária.');
                }
            }
            DB::table('cis_cash_movements')->insert([
                'account_id'=>$account->id, 'type'=>$d['type'], 'amount'=>$this->decimal($amount),
                'reference'=>$reference, 'category'=>$d['category'],
                'description'=>$d['description'], 'effective_at'=>now('Africa/Maputo'),
                'evidence_reference'=>$d['evidence_reference']??null,
                'created_by'=>$request->user()->id, 'created_at'=>now(), 'updated_at'=>now(),
            ]);
        },1);
        return response()->json(['reference'=>$reference,'status'=>'confirmado'],201);
    }

    public function transfer(Request $request)
    {
        $this->access($request,true);
        $d=$request->validate([
            'source_account_id'=>['required','integer','min:1'],
            'destination_account_id'=>['required','integer','min:1','different:source_account_id'],
            'amount'=>['required','string','max:20'],
            'description'=>['required','string','min:4','max:500'],
            'evidence_reference'=>['required','string','min:5','max:255'],
            'idempotency_key'=>['required','uuid'],
        ]);
        $amount=$this->cents($d['amount']);
        abort_if($amount<=0,422);
        $reference='CIS-TRANS-'.$d['idempotency_key'];
        DB::transaction(function () use ($d,$request,$amount,$reference) {
            abort_if(DB::table('cis_cash_movements')->where('reference',$reference.'-S')->exists(),409,
                'Transferência já registada.');
            $ids=[$d['source_account_id'],$d['destination_account_id']]; sort($ids,SORT_NUMERIC);
            $accounts=DB::table('cis_cash_accounts')->whereIn('id',$ids)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($accounts->count()===2,422,'As duas contas devem existir.');
            $source=$accounts[$d['source_account_id']];$target=$accounts[$d['destination_account_id']];
            foreach ([$source,$target] as $account) {
                abort_unless($account->status==='activa' && $account->currency==='MZN',409,
                    'A transferência exige duas contas activas em MZN.');
            }
            abort_if($this->balance($source)<$amount,422,'Saldo insuficiente na conta de origem.');
            $group=(string)Str::uuid();
            foreach ([[$source,'transferencia_saida','-S'],[$target,'transferencia_entrada','-E']] as [$account,$type,$suffix]) {
                DB::table('cis_cash_movements')->insert([
                    'account_id'=>$account->id, 'type'=>$type,
                    'amount'=>$this->decimal($amount), 'reference'=>$reference.$suffix,
                    'category'=>'Transferência interna','description'=>$d['description'],
                    'transfer_group'=>$group, 'effective_at'=>now('Africa/Maputo'),
                    'evidence_reference'=>$d['evidence_reference'],
                    'created_by'=>$request->user()->id, 'created_at'=>now(), 'updated_at'=>now(),
                ]);
            }
        },1);
        return response()->json(['reference'=>$reference,'status'=>'confirmado'],201);
    }
}
