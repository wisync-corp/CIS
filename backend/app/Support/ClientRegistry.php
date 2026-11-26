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
    private static ?array $legacy = null;
    public const CONDUTA_BLACK = 'Lista Negra';
    public const CONDUTA_DEFAULT = 'Padrão';

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
        self::$legacy = null;
    }

    /** Mutuários com «Conduta» = Lista Negra no cadastro original (ex.: marcados no sistema antigo). */
    public static function legacyBlackIds(): array
    {
        if (self::$legacy !== null) return self::$legacy;
        try {
            $db = DB::connection('mysql_legacy');
            if (!$db->getSchemaBuilder()->hasColumn('Clientes', 'Conduta')) return self::$legacy = [];
            return self::$legacy = $db->table('Clientes')->whereRaw("LOWER(TRIM(`Conduta`)) = 'lista negra'")->pluck('ID')->map(fn ($v) => (int) $v)->all();
        } catch (\Throwable) {
            return self::$legacy = [];
        }
    }

    public static function conduta(int $id): ?string
    {
        try {
            $db = DB::connection('mysql_legacy');
            return $db->getSchemaBuilder()->hasColumn('Clientes', 'Conduta') ? $db->table('Clientes')->where('ID', $id)->value('Conduta') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Escreve a «Conduta» no cadastro original (Padrão / Lista Negra); sem permissão não bloqueia. */
    public static function setConduta(int $id, string $value): bool
    {
        try {
            if (!filter_var(env('CIS_CLIENTES_EDIT_APPROVED', false), FILTER_VALIDATE_BOOLEAN)) return false;
            $db = DB::connection('mysql_editor');
            if (!$db->getSchemaBuilder()->hasColumn('Clientes', 'Conduta')) return false;
            $db->table('Clientes')->where('ID', $id)->update(['Conduta' => $value]);
            self::$legacy = null;
            return true;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
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
        $row = DB::table('cis_client_blacklist as b')->leftJoin('users as u', 'u.id', '=', 'b.added_by')
            ->where('b.client_id', $id)->whereNull('b.removed_at')->orderByDesc('b.id')
            ->first(['b.id', 'b.client_id', 'b.motivo', 'b.created_at', 'u.name as por']);
        if ($row) return $row;
        return in_array($id, self::legacyBlackIds(), true)
            ? (object) ['id' => null, 'client_id' => $id, 'motivo' => 'Conduta «Lista Negra» no cadastro', 'created_at' => null, 'por' => null, 'legado' => true]
            : null;
    }

    /** IDs de todos os mutuários na lista negra. */
    public static function blacklistedIds(): array
    {
        if (!self::ready()) return [];
        $ids = DB::table('cis_client_blacklist')->whereNull('removed_at')->distinct()->pluck('client_id')->map(fn ($v) => (int) $v)->all();
        return array_values(array_unique(array_merge($ids, self::legacyBlackIds())));
    }

    /** Dias do critério interno de incumprimento prolongado (Definições › Cobrança). */
    public static function overdueLimit(): int
    {
        return max(1, (int) CompanySettings::num('dias_lista_negra'));
    }

    public static function motivoAutomatico(int $days, int $limit): string
    {
        return 'Incumprimento prolongado: '.$days.' dias de atraso no pagamento (critério interno: mais de '.$limit.' dias).';
    }

    /**
     * Mutuários com prestações em atraso há mais de $limit dias (fora da lista negra), na agência/carteira do utilizador.
     * @return array<int,array{id:int,nome:string,dias:int,contratos:int,prestacoes:int,valor_atraso:float,valor_divida:float}>
     */
    public static function overdueCandidates(?object $user, ?int $limit = null): array
    {
        $limit ??= self::overdueLimit();
        $tz = 'Africa/Maputo';
        $today = now($tz)->toDateString();
        $cut = now($tz)->subDays($limit)->toDateString();
        $legacy = DB::connection('mysql_legacy');
        $pending = "`Estado` IN ('Pendente','Pagamento Atrasado')";
        $q = $legacy->table('Contractos')->whereRaw($pending)->whereNotNull('Nº de Cliente');
        Branches::restrict($q, $user);
        $late = (clone $q)->where('Data de Cobrança', '<', $cut)
            ->selectRaw('`Nº de Cliente` AS c, MIN(`Data de Cobrança`) AS mais_antiga, COUNT(DISTINCT `Nr do Contracto`) AS contratos, COUNT(*) AS prestacoes')
            ->groupBy('Nº de Cliente')->get()->keyBy(fn ($r) => (int) $r->c);
        if ($late->isEmpty()) return [];
        $black = array_flip(self::blacklistedIds());
        $ids = array_values(array_filter($late->keys()->all(), fn ($id) => !isset($black[$id])));
        if (!$ids) return [];
        $sums = (clone $q)->whereIn('Nº de Cliente', $ids)
            ->selectRaw('`Nº de Cliente` AS c, ROUND(SUM(CASE WHEN `Data de Cobrança` < ? THEN COALESCE(`Valor a Pagar`,0) ELSE 0 END),2) AS atraso, ROUND(SUM(COALESCE(`Valor a Pagar`,0)),2) AS divida', [$today])
            ->groupBy('Nº de Cliente')->get()->keyBy(fn ($r) => (int) $r->c);
        $names = $legacy->table('Clientes')->whereIn('ID', $ids)->pluck('Nome Completo', 'ID');
        $out = [];
        foreach ($ids as $id) {
            $l = $late[$id];
            $out[] = ['id' => $id, 'nome' => (string) ($names[$id] ?? '#'.$id),
                'dias' => (int) \Carbon\CarbonImmutable::parse(substr((string) $l->mais_antiga, 0, 10))->diffInDays(\Carbon\CarbonImmutable::parse($today)),
                'contratos' => (int) $l->contratos, 'prestacoes' => (int) $l->prestacoes,
                'valor_atraso' => (float) ($sums[$id]->atraso ?? 0), 'valor_divida' => (float) ($sums[$id]->divida ?? 0)];
        }
        usort($out, fn ($a, $b) => $b['dias'] <=> $a['dias']);
        return $out;
    }

    /** 422 se o mutuário estiver na lista negra. */
    public static function requireClear(int $id, string $who = 'Este mutuário'): void
    {
        $b = self::blacklisted($id);
        abort_if($b, 422, $who.' está na lista negra (central de risco): '.$b?->motivo.'. Não pode receber crédito nem ser avalista.');
    }
}
