<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agências: quem vê o quê.
 *
 * Utilizador com agência → só contratos e contas da sua agência.
 * Utilizador sem agência («todas as agências») → vê tudo e pode filtrar por agência.
 * Contratos sem correspondência em cis_contract_branch pertencem à agência principal.
 */
final class Branches
{
    private static ?bool $ready = null;
    private static ?array $map = null;

    public static function ready(): bool
    {
        if (self::$ready !== null) return self::$ready;
        try {
            return self::$ready = Schema::hasTable('cis_branches') && Schema::hasTable('cis_contract_branch')
                && Schema::hasColumn('users', 'branch_id');
        } catch (\Throwable) {
            return self::$ready = false;
        }
    }

    public static function reset(): void
    {
        self::$ready = null;
        self::$map = null;
    }

    public static function principalId(): ?int
    {
        if (!self::ready()) return null;
        $id = DB::table('cis_branches')->orderByDesc('principal')->orderBy('id')->value('id');
        return $id !== null ? (int) $id : null;
    }

    public static function userBranch(?object $user): ?int
    {
        if (!$user || !self::ready()) return null;
        $b = $user->branch_id ?? null;
        return $b !== null ? (int) $b : null;
    }

    /** Agência a aplicar: a do utilizador (obrigatória) ou o filtro pedido por quem vê todas. */
    public static function scope(?object $user, mixed $requested = null): ?int
    {
        if (!self::ready()) return null;
        $own = self::userBranch($user);
        if ($own !== null) return $own;
        return is_numeric($requested) && (int) $requested > 0 ? (int) $requested : null;
    }

    /** contrato → agência (só as correspondências gravadas). */
    private static function map(): array
    {
        if (self::$map !== null) return self::$map;
        return self::$map = self::ready()
            ? DB::table('cis_contract_branch')->pluck('branch_id', 'contrato')->map(fn ($v) => (int) $v)->all()
            : [];
    }

    public static function contractBranch(string $numero): ?int
    {
        if (!self::ready()) return null;
        return self::map()[$numero] ?? self::principalId();
    }

    /** Restringe uma consulta de Contractos (ou outra com o n.º do contrato) à agência indicada. */
    public static function applyToContracts($query, ?int $branch, string $column = 'Nr do Contracto'): void
    {
        if ($branch === null || !self::ready()) return;
        $map = self::map();
        if ($branch === self::principalId()) {
            $others = array_keys(array_filter($map, fn ($b) => $b !== $branch));
            if ($others) $query->whereNotIn($column, $others);
            return;
        }
        $mine = array_keys(array_filter($map, fn ($b) => $b === $branch));
        $mine ? $query->whereIn($column, $mine) : $query->whereRaw('1 = 0');
    }

    /** 403 se o contrato for de outra agência ou estiver fora da carteira do utilizador. */
    public static function requireContract(?object $user, string $numero): void
    {
        Portfolio::requireContract($user, $numero);
        $own = self::userBranch($user);
        if ($own === null) return;
        abort_unless(self::contractBranch($numero) === $own, 403, 'Este contrato pertence a outra agência.');
    }

    /** Agência (obrigatória ou filtro pedido) + carteira do utilizador, numa só chamada. */
    public static function restrict($query, ?object $user, mixed $requested = null, string $column = 'Nr do Contracto'): void
    {
        self::applyToContracts($query, self::scope($user, $requested), $column);
        Portfolio::applyToContracts($query, $user, $column);
    }

    /** Contas da Caixa de uma agência: as atribuídas a ela e, na agência principal, as partilhadas. */
    public static function accountsOf(int $branch): array
    {
        if (!Schema::hasTable('cis_cash_account_branch')) return [];
        $mapped = DB::table('cis_cash_account_branch')->pluck('branch_id', 'account_id')->map(fn ($v) => (int) $v)->all();
        $ids = array_keys(array_filter($mapped, fn ($b) => $b === $branch));
        if ($branch === self::principalId() && Schema::hasTable('cis_cash_accounts')) {
            $ids = array_merge($ids, DB::table('cis_cash_accounts')->whereNotIn('id', array_keys($mapped) ?: [0])->pluck('id')->map(fn ($v) => (int) $v)->all());
        }
        return array_values(array_unique($ids));
    }

    /**
     * Extracto de uma agência e da carteira: movimentos de contratos da agência/carteira e,
     * sem contrato (Caixa geral), os das contas da agência. Carteira restrita → só os dos seus contratos.
     */
    public static function restrictExtracto($query, ?object $user, mixed $requested = null): void
    {
        $branch = self::scope($user, $requested);
        $own = Portfolio::contracts($user);
        if ($branch === null && $own === null) return;
        $query->where(function ($w) use ($branch, $own) {
            $w->where(function ($c) use ($branch, $own) {
                $c->whereNotNull('Contracto')->where('Contracto', '<>', '');
                self::applyToContracts($c, $branch, 'Contracto');
                if ($own !== null) $own ? $c->whereIn('Contracto', $own) : $c->whereRaw('1 = 0');
            });
            if ($own === null && $branch !== null && Schema::hasColumn('Extracto', 'account_id')) {
                $accs = self::accountsOf($branch);
                $w->orWhere(function ($g) use ($accs) {
                    $g->where(fn ($z) => $z->whereNull('Contracto')->orWhere('Contracto', ''));
                    $accs ? $g->whereIn('account_id', $accs) : $g->whereRaw('1 = 0');
                });
            }
        });
    }

    public static function assignContract(string $numero, int $branch, ?int $by): void
    {
        if (!self::ready()) return;
        DB::table('cis_contract_branch')->updateOrInsert(['contrato' => $numero],
            ['branch_id' => $branch, 'assigned_by' => $by, 'assigned_at' => now()]);
        self::$map = null;
    }

    /** Contas da Caixa visíveis: as da agência do utilizador e as partilhadas (sem agência). */
    public static function accountVisible(?object $user, int $accountId): bool
    {
        $own = self::userBranch($user);
        if ($own === null || !Schema::hasTable('cis_cash_account_branch')) return true;
        $b = DB::table('cis_cash_account_branch')->where('account_id', $accountId)->value('branch_id');
        return $b === null || (int) $b === $own;
    }

    /** Contas visíveis a um utilizador com agência (as dela + partilhadas); null = todas. */
    public static function visibleAccountIds(?object $user): ?array
    {
        $own = self::userBranch($user);
        if ($own === null || !Schema::hasTable('cis_cash_accounts')) return null;
        return DB::table('cis_cash_accounts')->pluck('id')->map(fn ($v) => (int) $v)
            ->filter(fn ($id) => self::accountVisible($user, $id))->values()->all();
    }

    public static function label(?int $branch): ?string
    {
        if ($branch === null || !self::ready()) return null;
        return DB::table('cis_branches')->where('id', $branch)->value('nome');
    }
}
