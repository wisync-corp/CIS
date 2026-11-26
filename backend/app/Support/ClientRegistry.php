<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Perfil CIS do mutuário (singular/colectiva, fotografia) e lista negra. */
final class ClientRegistry
{
    public const FORMAS = ['EI' => 'Empresário em nome individual', 'Lda' => 'Sociedade por quotas (Lda.)', 'Unipessoal' => 'Sociedade unipessoal',
        'SA' => 'Sociedade anónima (S.A.)', 'SAS' => 'Sociedade anónima simplificada', 'Associacao' => 'Associação', 'Fundacao' => 'Fundação',
        'Cooperativa' => 'Cooperativa', 'ONG' => 'ONG', 'Outra' => 'Outra'];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready !== null) return self::$ready;
        try {
            return self::$ready = Schema::hasTable('cis_client_profile') && Schema::hasTable('cis_client_blacklist');
        } catch (\Throwable) {
            return self::$ready = false;
        }
    }

    public static function reset(): void
    {
        self::$ready = null;
    }

    public static function profile(int $id): ?object
    {
        return self::ready() ? DB::table('cis_client_profile')->where('client_id', $id)->first() : null;
    }

    public static function tipo(int $id): string
    {
        return (self::profile($id)->tipo ?? 'singular') === 'colectiva' ? 'colectiva' : 'singular';
    }

    /** Entrada activa na lista negra (ou null). */
    public static function blacklisted(int $id): ?object
    {
        if (!self::ready()) return null;
        return DB::table('cis_client_blacklist as b')->leftJoin('users as u', 'u.id', '=', 'b.added_by')
            ->where('b.client_id', $id)->whereNull('b.removed_at')->orderByDesc('b.id')
            ->first(['b.id', 'b.client_id', 'b.motivo', 'b.created_at', 'u.name as por']);
    }

    /** IDs de todos os mutuários na lista negra. */
    public static function blacklistedIds(): array
    {
        if (!self::ready()) return [];
        return DB::table('cis_client_blacklist')->whereNull('removed_at')->distinct()->pluck('client_id')->map(fn ($v) => (int) $v)->all();
    }

    /** 422 se o mutuário estiver na lista negra. */
    public static function requireClear(int $id, string $who = 'Este mutuário'): void
    {
        $b = self::blacklisted($id);
        abort_if($b, 422, $who.' está na lista negra (central de risco): '.$b?->motivo.'. Não pode receber crédito nem ser avalista.');
    }
}
