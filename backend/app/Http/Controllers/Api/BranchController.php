<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Branches;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Agências (branches): cadastro e atribuição de contratos e contas da Caixa. */
final class BranchController extends Controller
{
    private function ready(): void
    {
        abort_unless(Branches::ready(), 503, 'Agências ainda não instaladas. Na VPS execute: bash deploy/install-user-management.sh');
    }

    /** Lista simples (todas as funções): para filtros e etiquetas. */
    public function list(Request $r): JsonResponse
    {
        Permissions::require($r->user(), 'ver');
        if (!Branches::ready()) return response()->json(['agencias' => [], 'minha' => null, 'instalado' => false]);
        return response()->json([
            'agencias' => DB::table('cis_branches')->where('activo', true)->orderByDesc('principal')->orderBy('nome')->get(['id', 'codigo', 'nome', 'cidade', 'principal']),
            'minha' => Branches::userBranch($r->user()), 'instalado' => true,
        ]);
    }

    public function index(Request $r): JsonResponse
    {
        Permissions::require($r->user(), 'definicoes');
        $this->ready();
        $principal = Branches::principalId();
        $contracts = DB::connection('mysql_legacy')->table('Contractos')->select('Nr do Contracto', 'Cidade')->distinct()->get();
        $map = DB::table('cis_contract_branch')->pluck('branch_id', 'contrato');
        $per = [];
        foreach ($contracts->unique('Nr do Contracto') as $c) {
            $b = (int) ($map[$c->{'Nr do Contracto'}] ?? $principal);
            $per[$b] = ($per[$b] ?? 0) + 1;
        }
        $users = DB::table('users')->where('active', true)->selectRaw('branch_id, COUNT(*) n')->groupBy('branch_id')->pluck('n', 'branch_id');
        $accMap = DB::table('cis_cash_account_branch')->pluck('branch_id', 'account_id');
        $accounts = DB::table('cis_cash_accounts')->orderBy('code')->get(['id', 'code', 'name', 'status'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'codigo' => $a->code, 'nome' => $a->name, 'estado' => $a->status,
                'branch_id' => isset($accMap[$a->id]) ? (int) $accMap[$a->id] : null]);
        $branches = DB::table('cis_branches')->orderByDesc('principal')->orderBy('nome')->get()->map(fn ($b) => (array) $b + [
            'contratos' => $per[$b->id] ?? 0, 'utilizadores' => (int) ($users[$b->id] ?? 0),
            'contas' => $accounts->where('branch_id', (int) $b->id)->count(),
        ]);
        $cities = $contracts->pluck('Cidade')->map(fn ($c) => trim((string) $c))->filter()->countBy()->sortDesc();
        return response()->json(['agencias' => $branches, 'contas' => $accounts->values(),
            'utilizadores_todas' => (int) ($users[''] ?? $users[null] ?? 0),
            'cidades' => $cities->map(fn ($n, $c) => ['cidade' => $c, 'contratos' => $n])->values()])->header('Cache-Control', 'no-store, private');
    }

    private function data(Request $r, ?int $id = null): array
    {
        $d = $r->validate([
            'codigo' => ['required', 'string', 'max:12', 'regex:/^[A-Z0-9-]+$/', Rule::unique('cis_branches', 'codigo')->ignore($id)],
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            'provincia' => ['nullable', 'string', 'max:60'], 'cidade' => ['nullable', 'string', 'max:100'],
            'morada' => ['nullable', 'string', 'max:255'], 'telefone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'], 'responsavel_id' => ['nullable', 'integer', 'exists:users,id'],
            'activo' => ['sometimes', 'boolean'],
            'credito_minimo' => ['nullable', 'integer', 'min:1', 'max:16777216'], 'credito_maximo' => ['nullable', 'integer', 'min:1', 'max:16777216'],
            'taxa_minima' => ['nullable', 'numeric', 'min:0', 'max:100'], 'taxa_maxima' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], ['codigo.regex' => 'Código: só maiúsculas, números e hífen (ex.: MAT-01).', 'codigo.unique' => 'Já existe uma agência com este código.',
        ]);
        foreach ([['credito_minimo', 'credito_maximo', 'O montante máximo tem de ser igual ou superior ao mínimo.'], ['taxa_minima', 'taxa_maxima', 'A taxa máxima tem de ser igual ou superior à mínima.']] as [$lo, $hi, $msg]) {
            if (isset($d[$lo], $d[$hi]) && (float) $d[$hi] < (float) $d[$lo]) throw \Illuminate\Validation\ValidationException::withMessages([$hi => $msg]);
        }
        // Limites de crédito: só quando as colunas existem (deploy/install-credit-proposals.sh).
        if (!\Illuminate\Support\Facades\Schema::hasColumn('cis_branches', 'credito_minimo')) unset($d['credito_minimo'], $d['credito_maximo'], $d['taxa_minima'], $d['taxa_maxima']);
        return $d;
    }

    public function store(Request $r): JsonResponse
    {
        Permissions::require($r->user(), 'definicoes');
        $this->ready();
        $r->merge(['codigo' => strtoupper(trim((string) $r->input('codigo')))]);
        $d = $this->data($r);
        $id = DB::table('cis_branches')->insertGetId($d + ['principal' => false, 'activo' => $d['activo'] ?? true, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['id' => $id, 'notice' => 'Agência «'.$d['nome'].'» criada.'], 201);
    }

    public function update(Request $r, int $id): JsonResponse
    {
        Permissions::require($r->user(), 'definicoes');
        $this->ready();
        $b = DB::table('cis_branches')->where('id', $id)->first();
        abort_unless($b, 404, 'Agência inexistente.');
        $r->merge(['codigo' => strtoupper(trim((string) $r->input('codigo', $b->codigo)))]);
        $d = $this->data($r, $id);
        if (array_key_exists('activo', $d) && !$d['activo']) {
            abort_if($b->principal, 422, 'A agência principal não pode ser desactivada.');
            abort_if(DB::table('users')->where('branch_id', $id)->where('active', true)->exists(), 422,
                'Há utilizadores activos nesta agência; mude-os de agência antes de a desactivar.');
        }
        DB::table('cis_branches')->where('id', $id)->update($d + ['updated_at' => now()]);
        return response()->json(['notice' => 'Agência actualizada.']);
    }

    /** Atribui à agência todos os contratos de uma cidade (coluna Cidade dos contratos). */
    public function assignCity(Request $r, int $id): JsonResponse
    {
        Permissions::require($r->user(), 'definicoes');
        $this->ready();
        abort_unless(DB::table('cis_branches')->where('id', $id)->exists(), 404, 'Agência inexistente.');
        $city = trim((string) $r->validate(['cidade' => ['required', 'string', 'max:100']])['cidade']);
        $nums = DB::connection('mysql_legacy')->table('Contractos')->whereRaw('TRIM(`Cidade`) = ?', [$city])->distinct()->pluck('Nr do Contracto');
        DB::transaction(function () use ($nums, $id, $r) {
            foreach ($nums as $n) Branches::assignContract((string) $n, $id, $r->user()->id);
        });
        return response()->json(['notice' => $nums->count().' contrato(s) de '.$city.' atribuído(s) à agência.']);
    }

    public function assignContract(Request $r, string $numero): JsonResponse
    {
        Permissions::require($r->user(), 'definicoes');
        $this->ready();
        $id = (int) $r->validate(['branch_id' => ['required', 'integer', 'exists:cis_branches,id']])['branch_id'];
        abort_unless(DB::connection('mysql_legacy')->table('Contractos')->where('Nr do Contracto', $numero)->exists(), 404, 'Contrato inexistente.');
        Branches::assignContract($numero, $id, $r->user()->id);
        return response()->json(['notice' => 'Contrato atribuído à agência '.Branches::label($id).'.', 'agencia' => ['id' => $id, 'nome' => Branches::label($id)]]);
    }

    public function contractBranch(Request $r, string $numero): JsonResponse
    {
        Permissions::require($r->user(), 'ver');
        $b = Branches::contractBranch($numero);
        return response()->json(['agencia' => $b ? ['id' => $b, 'nome' => Branches::label($b)] : null]);
    }

    public function assignAccount(Request $r, int $accountId): JsonResponse
    {
        Permissions::require($r->user(), 'definicoes');
        $this->ready();
        abort_unless(DB::table('cis_cash_accounts')->where('id', $accountId)->exists(), 404, 'Conta inexistente.');
        $b = $r->validate(['branch_id' => ['nullable', 'integer', 'exists:cis_branches,id']])['branch_id'] ?? null;
        $b === null ? DB::table('cis_cash_account_branch')->where('account_id', $accountId)->delete()
            : DB::table('cis_cash_account_branch')->updateOrInsert(['account_id' => $accountId], ['branch_id' => $b]);
        return response()->json(['notice' => $b ? 'Conta atribuída à agência '.Branches::label((int) $b).'.' : 'Conta partilhada por todas as agências.']);
    }
}
