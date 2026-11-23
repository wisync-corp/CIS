<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Utilizadores do CIS (só quem tem a permissão «utilizadores», ou seja, o administrador).
 * Contas novas e reposições recebem uma palavra-passe temporária, mostrada uma única vez,
 * que tem de ser trocada no primeiro acesso. Tudo fica em cis_user_audit.
 */
final class UserController extends Controller
{
    private function ready(Request $r): void
    {
        Permissions::require($r->user(), 'utilizadores');
        abort_unless(Schema::hasColumn('users', 'must_change_password') && Schema::hasTable('cis_user_audit'), 503,
            'Gestão de utilizadores ainda não instalada. Na VPS execute: bash deploy/install-user-management.sh');
    }

    private static function temporaryPassword(): string
    {
        // 12 caracteres legíveis (sem 0/O, 1/l/I), com letras e números.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        do {
            $p = '';
            for ($i = 0; $i < 12; $i++) $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        } while (!preg_match('/[A-Z]/', $p) || !preg_match('/[a-z]/', $p) || !preg_match('/[0-9]/', $p));
        return $p;
    }

    private static function audit(?int $actor, int $user, string $accao, ?string $detalhe = null): void
    {
        DB::table('cis_user_audit')->insert(['actor_id' => $actor, 'user_id' => $user, 'accao' => $accao, 'detalhe' => $detalhe, 'created_at' => now()]);
    }

    private static function present(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'telefone' => $u->telefone, 'role' => $u->role,
            'role_label' => Permissions::ROLES[$u->role]['label'] ?? $u->role, 'active' => (bool) $u->active,
            'must_change_password' => (bool) $u->must_change_password,
            'last_login_at' => $u->last_login_at ? (string) $u->last_login_at : null, 'created_at' => $u->created_at ? (string) $u->created_at : null,
            'sessoes' => $u->tokens()->count(),
            'branch_id' => isset($u->branch_id) && $u->branch_id !== null ? (int) $u->branch_id : null,
            'agencia' => isset($u->branch_id) && $u->branch_id !== null ? \App\Support\Branches::label((int) $u->branch_id) : null];
    }

    /** Pelo menos um administrador activo tem de existir sempre. */
    private static function keepsAnAdmin(User $target, string $newRole, bool $newActive): bool
    {
        if ($newRole === 'administrador' && $newActive) return true;
        return User::query()->where('role', 'administrador')->where('active', true)->where('id', '!=', $target->id)->exists();
    }

    public function roles(Request $r): JsonResponse
    {
        Permissions::require($r->user(), 'utilizadores');
        return response()->json([
            'funcoes' => collect(Permissions::ROLES)->map(fn ($v, $k) => ['id' => $k, 'label' => $v['label'], 'descricao' => $v['descricao'],
                'permissoes' => in_array('*', $v['perms'], true) ? array_keys(Permissions::PERMISSIONS) : $v['perms']])->values(),
            'permissoes' => collect(Permissions::PERMISSIONS)->map(fn ($v, $k) => ['id' => $k, 'label' => $v])->values(),
        ]);
    }

    public function index(Request $r): JsonResponse
    {
        $this->ready($r);
        $users = User::query()->orderByDesc('active')->orderBy('name')->get();
        $audit = DB::table('cis_user_audit as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->leftJoin('users as t', 't.id', '=', 'a.user_id')
            ->orderByDesc('a.id')->limit(40)->get(['a.accao', 'a.detalhe', 'a.created_at', 'u.name as actor', 't.name as alvo']);
        return response()->json(['utilizadores' => $users->map(fn ($u) => self::present($u))->values(), 'auditoria' => $audit])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $r): JsonResponse
    {
        $this->ready($r);
        $d = $r->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'telefone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(Permissions::roleKeys())],
            'branch_id' => ['nullable', 'integer', Rule::exists('cis_branches', 'id')],
        ], ['email.unique' => 'Já existe um utilizador com este email.']);
        $temp = self::temporaryPassword();
        $u = DB::transaction(function () use ($d, $temp, $r) {
            $u = User::create(['name' => trim($d['name']), 'email' => strtolower(trim($d['email'])), 'password' => $temp,
                'role' => $d['role'], 'active' => true]);
            $u->forceFill(['telefone' => $d['telefone'] ?? null, 'must_change_password' => true, 'created_by' => $r->user()->id]
                + (\App\Support\Branches::ready() ? ['branch_id' => $d['branch_id'] ?? null] : []))->save();
            self::audit($r->user()->id, $u->id, 'criado', 'Função: '.(Permissions::ROLES[$d['role']]['label'] ?? $d['role']));
            return $u;
        });
        return response()->json(['utilizador' => self::present($u->fresh()), 'palavra_passe_temporaria' => $temp,
            'notice' => 'Utilizador criado. Entregue a palavra-passe temporária em mão ou por mensagem: só aparece agora e tem de ser trocada no primeiro acesso.'], 201);
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $this->ready($r);
        $u = User::query()->findOrFail($id);
        $d = $r->validate([
            'name' => ['sometimes', 'string', 'min:3', 'max:120'],
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($u->id)],
            'telefone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'role' => ['sometimes', Rule::in(Permissions::roleKeys())],
            'active' => ['sometimes', 'boolean'],
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('cis_branches', 'id')],
        ], ['email.unique' => 'Já existe um utilizador com este email.']);
        $me = $r->user();
        $role = $d['role'] ?? $u->role;
        $active = array_key_exists('active', $d) ? (bool) $d['active'] : (bool) $u->active;
        if ($u->id === $me->id) {
            abort_if($role !== $u->role, 422, 'Não pode alterar a sua própria função.');
            abort_if(!$active, 422, 'Não pode desactivar a sua própria conta.');
        }
        abort_unless(self::keepsAnAdmin($u, $role, $active), 422, 'Tem de existir pelo menos um administrador activo.');
        $changes = [];
        foreach (['name' => 'nome', 'email' => 'email', 'telefone' => 'telefone'] as $k => $label) {
            if (array_key_exists($k, $d) && (string) $d[$k] !== (string) $u->{$k}) $changes[] = $label;
        }
        $branchChanged = \App\Support\Branches::ready() && array_key_exists('branch_id', $d) && (int) ($d['branch_id'] ?? 0) !== (int) ($u->branch_id ?? 0);
        if ($branchChanged) $changes[] = 'agência: '.(\App\Support\Branches::label($d['branch_id'] ?? null) ?? 'todas');
        if ($role !== $u->role) $changes[] = 'função: '.(Permissions::ROLES[$u->role]['label'] ?? $u->role).' → '.(Permissions::ROLES[$role]['label'] ?? $role);
        if ($active !== (bool) $u->active) $changes[] = $active ? 'activado' : 'desactivado';
        if (!$changes) return response()->json(['utilizador' => self::present($u), 'notice' => 'Sem alterações.']);
        $roleChanged = $role !== $u->role || $branchChanged;
        DB::transaction(function () use ($u, $d, $role, $active, $me, $changes, $roleChanged, $branchChanged) {
            $u->forceFill(array_filter([
                'name' => isset($d['name']) ? trim($d['name']) : null, 'email' => isset($d['email']) ? strtolower(trim($d['email'])) : null,
            ], fn ($v) => $v !== null) + ['role' => $role, 'active' => $active] + (array_key_exists('telefone', $d) ? ['telefone' => $d['telefone']] : [])
                + ($branchChanged ? ['branch_id' => $d['branch_id'] ?? null] : []))->save();
            // Mudança de função ou desactivação termina as sessões abertas.
            if (!$active || $roleChanged) $u->tokens()->delete();
            self::audit($me->id, $u->id, 'alterado', implode('; ', $changes));
        });
        return response()->json(['utilizador' => self::present($u->fresh()), 'notice' => 'Alterações guardadas: '.implode('; ', $changes).'.']);
    }

    public function resetPassword(Request $r, int $id): JsonResponse
    {
        $this->ready($r);
        $u = User::query()->findOrFail($id);
        $temp = self::temporaryPassword();
        DB::transaction(function () use ($u, $temp, $r) {
            $u->forceFill(['password' => $temp, 'must_change_password' => true])->save();
            $u->tokens()->delete();
            self::audit($r->user()->id, $u->id, 'palavra_passe_reposta', null);
        });
        return response()->json(['palavra_passe_temporaria' => $temp,
            'notice' => 'Palavra-passe reposta e sessões terminadas. A nova palavra-passe temporária só aparece agora.']);
    }

    public function endSessions(Request $r, int $id): JsonResponse
    {
        $this->ready($r);
        $u = User::query()->findOrFail($id);
        $n = $u->tokens()->count();
        $keep = $u->id === $r->user()->id ? $r->user()->currentAccessToken()?->id : null;
        $u->tokens()->when($keep, fn ($q) => $q->where('id', '!=', $keep))->delete();
        self::audit($r->user()->id, $u->id, 'sessoes_terminadas', $n.' sessão(ões)');
        return response()->json(['notice' => 'Sessões terminadas.']);
    }
}
