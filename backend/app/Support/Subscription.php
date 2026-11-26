<?php
namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subscrição do CIS: plano, utilizadores incluídos/extra, validade e pagamentos.
 * Sem as tabelas instaladas não há limites (o CIS continua a funcionar como antes).
 */
final class Subscription
{
    private static ?bool $ready = null;

    public static function reset(): void { self::$ready = null; }

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('cis_subscription') && Schema::hasTable('cis_subscription_payments');
    }

    public static function today(): CarbonImmutable { return CarbonImmutable::now('Africa/Maputo')->startOfDay(); }

    public static function plans(): array { return config('cis_subscription.planos'); }

    public static function plan(string $id): ?array { return self::plans()[$id] ?? null; }

    /** Linha única da subscrição; na primeira leitura abre o período de teste. */
    public static function current(): object
    {
        $row = DB::table('cis_subscription')->orderBy('id')->first();
        if ($row) return $row;
        $t = self::today();
        DB::table('cis_subscription')->insert(['plano' => config('cis_subscription.plano_inicial'), 'utilizadores_extra' => 0,
            'ciclo' => 'mensal', 'estado' => 'teste', 'inicio' => $t->toDateString(),
            'valido_ate' => $t->addDays((int) config('cis_subscription.dias_teste'))->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('cis_subscription')->orderBy('id')->first();
    }

    public static function limit(object $s): int
    {
        return (int) (self::plan($s->plano)['utilizadores'] ?? 0) + (int) $s->utilizadores_extra;
    }

    public static function activeUsers(): int
    {
        return \App\Models\User::query()->where('active', true)->count();
    }

    /** teste | activa | a_vencer | carencia | expirada, com os dias até (ou desde) o vencimento. */
    public static function status(object $s): array
    {
        $t = self::today();
        $end = CarbonImmutable::parse($s->valido_ate, 'Africa/Maputo')->startOfDay();
        $days = (int) $t->diffInDays($end, false);
        $warn = (int) config('cis_subscription.dias_aviso');
        $grace = (int) config('cis_subscription.dias_carencia');
        $state = $days < -$grace ? 'expirada' : ($days < 0 ? 'carencia' : ($s->estado === 'teste' ? 'teste' : ($days <= $warn ? 'a_vencer' : 'activa')));
        return ['estado' => $state, 'dias' => $days];
    }

    /** Preço de um período: mensal = 1 mês; anual = 12 meses pelo preço de (12 - meses grátis). */
    public static function quote(string $planId, int $extra, string $cycle): array
    {
        $p = self::plan($planId);
        abort_if(!$p, 422, 'Plano desconhecido.');
        $monthly = (float) $p['preco'] + $extra * (float) $p['extra'];
        $months = $cycle === 'anual' ? 12 : 1;
        $paid = $cycle === 'anual' ? 12 - (int) config('cis_subscription.meses_gratis_anual') : 1;
        return ['mensal' => round($monthly, 2), 'meses' => $months, 'meses_pagos' => $paid, 'total' => round($monthly * $paid, 2),
            'poupanca' => round($monthly * ($months - $paid), 2), 'utilizadores' => (int) $p['utilizadores'] + $extra];
    }

    /** Novo utilizador activo (criar ou reactivar) dentro do limite e com a subscrição em dia. */
    public static function requireSeat(): void
    {
        if (!self::ready()) return;
        $s = self::current();
        $st = self::status($s);
        abort_if($st['estado'] === 'expirada', 402,
            'A subscrição do CIS expirou. Renove em Definições › Subscrição para activar mais utilizadores.');
        $limit = self::limit($s);
        abort_if(self::activeUsers() >= $limit, 402,
            "Limite de utilizadores atingido ({$limit} no plano ".(self::plan($s->plano)['nome'] ?? $s->plano).'). '.
            'Acrescente utilizadores em Definições › Subscrição ou desactive uma conta que já não use.');
    }

    public static function newReference(): string
    {
        do {
            $ref = 'CIS'.self::today()->format('ym').strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        } while (DB::table('cis_subscription_payments')->where('referencia', $ref)->exists());
        return $ref;
    }

    /** Confirma um pagamento pendente (uma só vez) e prolonga a validade a partir do fim actual. */
    public static function confirm(string $reference, string $by, ?string $operatorTx = null): ?object
    {
        return DB::transaction(function () use ($reference, $by, $operatorTx) {
            $p = DB::table('cis_subscription_payments')->where('referencia', $reference)->lockForUpdate()->first();
            if (!$p || $p->estado !== 'pendente') return $p;
            $s = self::current();
            $t = self::today();
            $end = CarbonImmutable::parse($s->valido_ate, 'Africa/Maputo')->startOfDay();
            // Teste ou já vencida: conta a partir de hoje; activa: soma ao fim actual (não se perdem dias).
            $from = ($s->estado === 'teste' || $end->lt($t)) ? $t : $end;
            $to = $from->addMonthsNoOverflow((int) $p->meses);
            DB::table('cis_subscription')->where('id', $s->id)->update(['plano' => $p->plano, 'utilizadores_extra' => $p->utilizadores_extra,
                'ciclo' => $p->ciclo, 'estado' => 'activa', 'valido_ate' => $to->toDateString(), 'updated_at' => now()]);
            DB::table('cis_subscription_payments')->where('id', $p->id)->update(['estado' => 'confirmado', 'valido_de' => $from->toDateString(),
                'valido_ate' => $to->toDateString(), 'confirmado_por' => mb_substr($by, 0, 60), 'confirmado_em' => now(),
                'operadora_transacao' => $operatorTx ?? $p->operadora_transacao, 'updated_at' => now()]);
            return DB::table('cis_subscription_payments')->where('id', $p->id)->first();
        });
    }

    public static function fail(string $reference, string $code, string $message): void
    {
        DB::table('cis_subscription_payments')->where('referencia', $reference)->where('estado', 'pendente')
            ->update(['estado' => 'falhado', 'operadora_codigo' => mb_substr($code, 0, 30), 'operadora_mensagem' => mb_substr($message, 0, 255), 'updated_at' => now()]);
    }

    /** Telemóvel moçambicano: 9 dígitos começados por 8; aceita +258/258 à frente. */
    public static function msisdn(string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', $raw);
        if (strlen($d) === 12 && str_starts_with($d, '258')) $d = substr($d, 3);
        return preg_match('/^8[2-7]\d{7}$/', $d) ? $d : null;
    }
}
