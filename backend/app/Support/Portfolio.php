<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carteiras: o que cada pessoa vê, além da agência.
 *
 * Gestor de carteira (cobrador) → só os contratos de que é responsável (cis_contract_owner)
 *   e os mutuários desses contratos.
 * Agente → só os mutuários que cadastrou ou lhe foram atribuídos (cis_client_owner)
 *   e os contratos desses mutuários.
 * As restantes funções não têm restrição de carteira (só a da agência).
 */
final class Portfolio
{
    /** Funções limitadas à sua carteira e o tipo de carteira. */
    public const OWN_ONLY = ['cobrador' => 'contratos', 'agente' => 'mutuarios'];

    private static ?bool $ready = null;
    private static array $cache = [];

    public static function ready(): bool
    {
        if (self::$ready !== null) return self::$ready;
        try {
            return self::$ready = Schema::hasTable('cis_contract_owner') && Schema::hasTable('cis_client_owner');
        } catch (\Throwable) {
            return self::$ready = false;
        }
    }

    public static function reset(): void
    {
        self::$ready = null;
        self::$cache = [];
    }

    /** 'contratos' | 'mutuarios' | null (sem restrição). */
    public static function kind(?object $user): ?string
    {
        if (!$user || !self::ready() || !empty($user->must_change_password)) return null;
        return self::OWN_ONLY[(string) ($user->role ?? '')] ?? null;
    }

    /** Números dos contratos visíveis; null = sem restrição de carteira. */
    public static function contracts(?object $user): ?array
    {
        $kind = self::kind($user);
        if ($kind === null) return null;
        $key = 'c'.$user->id;
        if (isset(self::$cache[$key])) return self::$cache[$key];
        if ($kind === 'contratos') {
            $list = DB::table('cis_contract_owner')->where('user_id', $user->id)->pluck('contrato')->map(fn ($v) => (string) $v)->all();
        } else {
            $clients = self::clients($user);
            $list = $clients ? DB::connection('mysql_legacy')->table('Contractos')->whereIn('Nº de Cliente', $clients)
                ->distinct()->pluck('Nr do Contracto')->map(fn ($v) => (string) $v)->all() : [];
        }
        return self::$cache[$key] = $list;
    }

    /** IDs dos mutuários visíveis; null = sem restrição de carteira. */
    public static function clients(?object $user): ?array
    {
        $kind = self::kind($user);
        if ($kind === null) return null;
        $key = 'm'.$user->id;
        if (isset(self::$cache[$key])) return self::$cache[$key];
        if ($kind === 'mutuarios') {
            $list = DB::table('cis_client_owner')->where('user_id', $user->id)->pluck('client_id')->map(fn ($v) => (int) $v)->all();
        } else {
            $contracts = self::contracts($user);
            $list = $contracts ? DB::connection('mysql_legacy')->table('Contractos')->whereIn('Nr do Contracto', $contracts)
                ->whereNotNull('Nº de Cliente')->distinct()->pluck('Nº de Cliente')->map(fn ($v) => (int) $v)->all() : [];
        }
        return self::$cache[$key] = $list;
    }

    public static function applyToContracts($query, ?object $user, string $column = 'Nr do Contracto'): void
    {
        $list = self::contracts($user);
        if ($list === null) return;
        $list ? $query->whereIn($column, $list) : $query->whereRaw('1 = 0');
    }

    public static function applyToClients($query, ?object $user, string $column = 'ID'): void
    {
        $list = self::clients($user);
        if ($list === null) return;
        $list ? $query->whereIn($column, $list) : $query->whereRaw('1 = 0');
    }

    public static function requireContract(?object $user, string $numero): void
    {
        $list = self::contracts($user);
        if ($list === null) return;
        abort_unless(in_array($numero, $list, true), 403, 'Este contrato não faz parte da sua carteira.');
    }

    public static function requireClient(?object $user, int $id): void
    {
        Branches::requireClient($user, $id);
        $list = self::clients($user);
        if ($list === null) return;
        abort_unless(in_array($id, $list, true), 403, 'Este mutuário não faz parte da sua carteira.');
    }

    public static function contractOwner(string $numero): ?int
    {
        if (!self::ready()) return null;
        $id = DB::table('cis_contract_owner')->where('contrato', $numero)->value('user_id');
        return $id !== null ? (int) $id : null;
    }

    public static function assignContract(string $numero, ?int $userId, ?int $by): void
    {
        if (!self::ready()) return;
        if ($userId === null) {
            DB::table('cis_contract_owner')->where('contrato', $numero)->delete();
        } else {
            DB::table('cis_contract_owner')->updateOrInsert(['contrato' => $numero],
                ['user_id' => $userId, 'assigned_by' => $by, 'assigned_at' => now()]);
        }
        self::$cache = [];
    }

    public static function clientOwner(int $id): ?int
    {
        if (!self::ready()) return null;
        $u = DB::table('cis_client_owner')->where('client_id', $id)->value('user_id');
        return $u !== null ? (int) $u : null;
    }

    public static function assignClient(int $id, ?int $userId, ?int $by): void
    {
        if (!self::ready()) return;
        if ($userId === null) {
            DB::table('cis_client_owner')->where('client_id', $id)->delete();
        } else {
            $q = DB::table('cis_client_owner')->where('client_id', $id);
            $q->exists()
                ? $q->update(['user_id' => $userId, 'assigned_by' => $by])
                : DB::table('cis_client_owner')->insert(['client_id' => $id, 'user_id' => $userId, 'assigned_by' => $by, 'created_at' => now()]);
        }
        self::$cache = [];
    }
}
