<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Branches;
use App\Support\Permissions;
use App\Support\Portfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Carteiras: gestor de carteira responsável por cada contrato e agente de cada mutuário.
 * Consultar: quem vê o contrato/mutuário. Atribuir: só o administrador (permissão «utilizadores»).
 */
final class PortfolioController extends Controller
{
    private function ready(): void
    {
        abort_unless(Portfolio::ready(), 503, 'Carteiras ainda não instaladas. Na VPS execute: bash deploy/install-user-management.sh');
    }

    /** Utilizadores activos de uma função (para escolher o responsável). */
    private static function people(string $role): array
    {
        $q = DB::table('users')->where('role', $role)->orderBy('name');
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'active')) $q->where('active', true);
        return $q->get(['id', 'name'])->map(fn ($u) => ['id' => (int) $u->id, 'nome' => $u->name])->all();
    }

    private static function name(?int $id): ?array
    {
        if ($id === null) return null;
        $n = DB::table('users')->where('id', $id)->value('name');
        return ['id' => $id, 'nome' => $n ?? 'Utilizador #'.$id];
    }

    private static function requireRole(int $userId, string $role, string $label): void
    {
        abort_unless(DB::table('users')->where('id', $userId)->where('role', $role)->exists(), 422,
            'O utilizador escolhido não tem a função '.$label.'.');
    }

    public function contract(Request $r, string $numero): JsonResponse
    {
        Permissions::require($r->user(), 'ver');
        Branches::requireContract($r->user(), $numero);
        if (!Portfolio::ready()) return response()->json(['instalado' => false, 'responsavel' => null, 'opcoes' => []]);
        $admin = Permissions::can($r->user(), 'utilizadores');
        return response()->json(['instalado' => true, 'responsavel' => self::name(Portfolio::contractOwner($numero)),
            'pode_atribuir' => $admin, 'opcoes' => $admin ? self::people('cobrador') : []]);
    }

    public function assignContract(Request $r, string $numero): JsonResponse
    {
        Permissions::require($r->user(), 'utilizadores');
        $this->ready();
        $uid = $r->validate(['user_id' => ['nullable', 'integer', 'min:1']])['user_id'] ?? null;
        abort_unless(DB::connection('mysql_legacy')->table('Contractos')->where('Nr do Contracto', $numero)->exists(), 404, 'Contrato inexistente.');
        if ($uid !== null) self::requireRole((int) $uid, 'cobrador', 'Gestor de carteira');
        Portfolio::assignContract($numero, $uid !== null ? (int) $uid : null, (int) $r->user()->id);
        return response()->json(['notice' => $uid ? 'Contrato atribuído a '.self::name((int) $uid)['nome'].'.' : 'Contrato sem gestor de carteira.',
            'responsavel' => self::name($uid !== null ? (int) $uid : null)]);
    }

    public function client(Request $r, int $id): JsonResponse
    {
        Permissions::require($r->user(), 'ver');
        Portfolio::requireClient($r->user(), $id);
        if (!Portfolio::ready()) return response()->json(['instalado' => false, 'agente' => null, 'opcoes' => []]);
        $admin = Permissions::can($r->user(), 'utilizadores');
        return response()->json(['instalado' => true, 'agente' => self::name(Portfolio::clientOwner($id)),
            'pode_atribuir' => $admin, 'opcoes' => $admin ? self::people('agente') : []]);
    }

    public function assignClient(Request $r, int $id): JsonResponse
    {
        Permissions::require($r->user(), 'utilizadores');
        $this->ready();
        $uid = $r->validate(['user_id' => ['nullable', 'integer', 'min:1']])['user_id'] ?? null;
        abort_unless(DB::connection('mysql_legacy')->table('Clientes')->where('ID', $id)->exists(), 404, 'Mutuário inexistente.');
        if ($uid !== null) self::requireRole((int) $uid, 'agente', 'Agente');
        Portfolio::assignClient($id, $uid !== null ? (int) $uid : null, (int) $r->user()->id);
        return response()->json(['notice' => $uid ? 'Mutuário atribuído a '.self::name((int) $uid)['nome'].'.' : 'Mutuário sem agente.',
            'agente' => self::name($uid !== null ? (int) $uid : null)]);
    }

    /** Resumo para o administrador: carteira de cada gestor de carteira e de cada agente. */
    public function summary(Request $r): JsonResponse
    {
        Permissions::require($r->user(), 'utilizadores');
        $this->ready();
        $legacy = DB::connection('mysql_legacy');
        $active = $legacy->table('Contractos')->whereIn('Estado', ['Pendente', 'Pagamento Atrasado'])->distinct()->pluck('Nr do Contracto')
            ->map(fn ($v) => (string) $v)->all();
        $owners = DB::table('cis_contract_owner')->pluck('user_id', 'contrato')->all();
        $activeSet = array_flip($active);
        $per = [];
        foreach ($owners as $c => $u) {
            $per[$u] ??= ['total' => 0, 'activos' => 0];
            $per[$u]['total']++;
            if (isset($activeSet[(string) $c])) $per[$u]['activos']++;
        }
        $cobradores = array_map(fn ($p) => $p + ['contratos' => $per[$p['id']]['total'] ?? 0, 'activos' => $per[$p['id']]['activos'] ?? 0],
            self::people('cobrador'));
        $clientsPer = DB::table('cis_client_owner')->selectRaw('user_id, COUNT(*) n')->groupBy('user_id')->pluck('n', 'user_id')->all();
        $agentes = array_map(fn ($p) => $p + ['mutuarios' => (int) ($clientsPer[$p['id']] ?? 0)], self::people('agente'));
        $cities = $legacy->table('Contractos')->whereIn('Estado', ['Pendente', 'Pagamento Atrasado'])
            ->whereNotNull('Cidade')->whereRaw("TRIM(`Cidade`) <> ''")
            ->selectRaw('TRIM(`Cidade`) AS cidade, COUNT(DISTINCT `Nr do Contracto`) AS contratos')->groupBy('cidade')->orderBy('cidade')->get();
        return response()->json([
            'cobradores' => $cobradores, 'agentes' => $agentes,
            'activos_sem_responsavel' => count(array_diff($active, array_map('strval', array_keys($owners)))),
            'contratos_activos' => count($active), 'cidades' => $cities,
            'mutuarios_sem_agente' => max(0, (int) $legacy->table('Clientes')->count() - (int) DB::table('cis_client_owner')->count()),
            'cidades_mutuarios' => $legacy->table('Clientes')->whereNotNull('Cidade')->whereRaw("TRIM(`Cidade`) <> ''")
                ->selectRaw('TRIM(`Cidade`) AS cidade, COUNT(*) AS mutuarios')->groupBy('cidade')->orderBy('cidade')->get(),
        ])->header('Cache-Control', 'no-store, private');
    }

    /** Atribuição em massa: contratos (de uma cidade e/ou agência) a um gestor de carteira. */
    public function assignBulk(Request $r): JsonResponse
    {
        Permissions::require($r->user(), 'utilizadores');
        $this->ready();
        $d = $r->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'cidade' => ['nullable', 'string', 'max:100'],
            'agencia' => ['nullable', 'integer', 'min:1'],
            'so_activos' => ['sometimes', 'boolean'],
            'so_sem_responsavel' => ['sometimes', 'boolean'],
        ]);
        self::requireRole((int) $d['user_id'], 'cobrador', 'Gestor de carteira');
        abort_if(trim((string) ($d['cidade'] ?? '')) === '' && empty($d['agencia']), 422, 'Escolha a cidade ou a agência.');
        $q = DB::connection('mysql_legacy')->table('Contractos');
        if (trim((string) ($d['cidade'] ?? '')) !== '') $q->whereRaw('TRIM(`Cidade`) = ?', [trim($d['cidade'])]);
        if (!empty($d['agencia'])) Branches::applyToContracts($q, (int) $d['agencia']);
        if ($d['so_activos'] ?? true) $q->whereIn('Estado', ['Pendente', 'Pagamento Atrasado']);
        $nums = $q->distinct()->pluck('Nr do Contracto')->map(fn ($v) => (string) $v)->all();
        if ($d['so_sem_responsavel'] ?? true) {
            $taken = DB::table('cis_contract_owner')->pluck('contrato')->map(fn ($v) => (string) $v)->all();
            $nums = array_values(array_diff($nums, $taken));
        }
        DB::transaction(function () use ($nums, $d, $r) {
            foreach ($nums as $n) Portfolio::assignContract($n, (int) $d['user_id'], (int) $r->user()->id);
        });
        return response()->json(['notice' => count($nums).' contrato(s) atribuído(s) a '.self::name((int) $d['user_id'])['nome'].'.', 'atribuidos' => count($nums)]);
    }

    /** Atribuição em massa: mutuários (de uma cidade e/ou agência) a um agente. */
    public function assignClientsBulk(Request $r): JsonResponse
    {
        Permissions::require($r->user(), 'utilizadores');
        $this->ready();
        $d = $r->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'cidade' => ['nullable', 'string', 'max:100'],
            'agencia' => ['nullable', 'integer', 'min:1'],
            'so_sem_agente' => ['sometimes', 'boolean'],
        ]);
        self::requireRole((int) $d['user_id'], 'agente', 'Agente');
        abort_if(trim((string) ($d['cidade'] ?? '')) === '' && empty($d['agencia']), 422, 'Escolha a cidade ou a agência.');
        $q = DB::connection('mysql_legacy')->table('Clientes');
        if (trim((string) ($d['cidade'] ?? '')) !== '') $q->whereRaw('TRIM(`Cidade`) = ?', [trim($d['cidade'])]);
        if (!empty($d['agencia'])) Branches::applyToClients($q, (int) $d['agencia']);
        $ids = $q->pluck('ID')->map(fn ($v) => (int) $v)->all();
        if ($d['so_sem_agente'] ?? true) {
            $taken = DB::table('cis_client_owner')->pluck('client_id')->map(fn ($v) => (int) $v)->all();
            $ids = array_values(array_diff($ids, $taken));
        }
        DB::transaction(function () use ($ids, $d, $r) {
            foreach ($ids as $id) Portfolio::assignClient($id, (int) $d['user_id'], (int) $r->user()->id);
        });
        return response()->json(['notice' => count($ids).' mutuário(s) atribuído(s) a '.self::name((int) $d['user_id'])['nome'].'.', 'atribuidos' => count($ids)]);
    }
}
