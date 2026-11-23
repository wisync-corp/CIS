<?php

namespace App\Support;

use App\Support\CompanySettings as CS;
use Illuminate\Support\Facades\DB;

/**
 * Histórico e score de um mutuário (mesma regra do relatório «Mutuários para novo crédito»):
 * score = % de prestações pagas sem atraso, −20 se tiver crédito activo em atraso.
 * Sem prestações pagas: sem score (cliente novo).
 */
final class CreditScore
{
    private const PAID = ['Pago', 'Pago em Atraso', 'Renovado'];
    private const OPEN = ['Pendente', 'Pagamento Atrasado'];

    public static function forClient(int $clientId): array
    {
        $today = now('Africa/Maputo')->toDateString();
        $rows = DB::connection('mysql_legacy')->table('Contractos')->where('Nº de Cliente', $clientId)
            ->get(['Nr do Contracto', 'Estado', 'Estado do Contrato', 'Data de Cobrança', 'Data de Pagamento', 'Quantia Creditada', 'Valor a Pagar', 'Valor de Juros', 'Valor de Multa']);
        $open = $rows->filter(fn ($r) => in_array($r->Estado, self::OPEN, true));
        $late = $open->filter(fn ($r) => ($d = substr((string) $r->{'Data de Cobrança'}, 0, 10)) !== '' && $d < $today);
        $paid = $rows->filter(fn ($r) => in_array($r->Estado, self::PAID, true));
        $paidLate = $paid->where('Estado', 'Pago em Atraso')->count();
        $maxLate = 0;
        foreach ($late as $r) $maxLate = max($maxLate, (int) floor((strtotime($today) - strtotime(substr((string) $r->{'Data de Cobrança'}, 0, 10))) / 86400));
        $openC = $open->pluck('Nr do Contracto')->unique()->count();
        $lateC = $late->pluck('Nr do Contracto')->unique()->count();
        $score = $paid->count() ? max(0, (int) round(100 * ($paid->count() - $paidLate) / $paid->count()) - ($lateC > 0 ? 20 : 0)) : null;
        $low = (int) CS::get('score_baixo_risco'); $mid = (int) CS::get('score_risco_medio'); $min = (int) CS::get('score_minimo');
        $writtenOff = $rows->where('Estado do Contrato', 'Abatido')->pluck('Nr do Contracto')->unique()->count();
        $class = $score === null ? 'Sem histórico' : ($score >= $low ? 'Baixo risco' : ($score >= $mid ? 'Risco médio' : 'Alto risco'));
        $debt = $open->sum(fn ($r) => max(0, (float) $r->{'Valor a Pagar'} - (float) $r->{'Valor de Juros'} - (float) ($r->{'Valor de Multa'} ?? 0)));
        return [
            'contratos' => $rows->pluck('Nr do Contracto')->unique()->count(),
            'contratos_activos' => $openC, 'contratos_em_atraso' => $lateC, 'contratos_abatidos' => $writtenOff,
            'max_dias_atraso' => $maxLate, 'capital_em_divida' => number_format($debt, 2, '.', ''),
            'prestacoes_pagas' => $paid->count(), 'pagas_em_atraso' => $paidLate,
            'score' => $score, 'classificacao' => $class, 'score_minimo' => $min,
        ];
    }

    /**
     * Análise de uma proposta: prestação estimada (motor dos contratos), taxa de esforço e alertas
     * segundo as regras de Definições › Credit scoring. Nunca bloqueia: a decisão é de quem aprova.
     */
    public static function analyse(array $p, array $history): array
    {
        $alerts = [];
        $plan = null;
        try {
            $weekly = str_contains($p['prazo'], 'Semana');
            $months = $weekly ? 0 : (int) explode(' ', $p['prazo'])[0];
            $rate = $p['taxa'] ?? null;
            if ($rate === null && $p['tipo_juro'] === 'Fixo') {
                $m = DB::table('cis_tabela_juros')->where('limite_inferior', '<=', $p['montante'])->where('limite_superior', '>=', $p['montante'])
                    ->where('periodo_referencia', (int) $p['periodo_dias'])->get(['taxa']);
                if ($m->count() === 1) $rate = (float) $m[0]->taxa;
            }
            if ($rate !== null) {
                $plan = \App\Services\LegacyContractPricing::plan((float) $p['montante'], (float) $rate, $p['tipo_juro'], $p['modalidade'], $months,
                    now('Africa/Maputo')->toDateString(), $p['estrategia'], $p['prazo'], (int) $p['periodo_dias']);
            } else {
                $alerts[] = 'Sem taxa na tabela de preços para este montante e período.';
            }
        } catch (\Throwable $e) {
            $alerts[] = 'Condições do crédito incompatíveis: '.(method_exists($e, 'errors') ? collect($e->errors())->flatten()->first() : $e->getMessage());
        }
        $total = $plan ? array_sum(array_column($plan['prestacoes'], 'pagar')) : null;
        $days = $plan ? max(1, (int) round((strtotime($plan['data_final']) - strtotime(now('Africa/Maputo')->toDateString())) / 86400)) : null;
        // Encargo mensal equivalente: total a pagar distribuído pelos meses do prazo (mínimo 1 mês).
        $monthly = $plan ? $total / max(1, $days / 30) : null;
        $income = (float) $p['rendimento_mensal'];
        $free = $income - (float) $p['despesas_mensais'] - (float) $p['outras_dividas_mensais'];
        $effort = $monthly !== null && $income > 0 ? round(100 * ($monthly + (float) $p['outras_dividas_mensais']) / $income, 1) : null;
        $maxEffort = (float) CS::get('taxa_esforco_max');
        if ($income <= 0) $alerts[] = 'Rendimento mensal não indicado.';
        elseif ($effort !== null && $maxEffort > 0 && $effort > $maxEffort) $alerts[] = "Taxa de esforço {$effort}% acima do máximo ({$maxEffort}%).";
        if ($monthly !== null && $free < $monthly) $alerts[] = 'O rendimento disponível não cobre o encargo mensal estimado.';
        if ($history['contratos_activos'] >= max(1, (int) CS::get('max_creditos_activos'))) $alerts[] = 'O mutuário já tem '.$history['contratos_activos'].' crédito(s) activo(s) (máximo '.max(1, (int) CS::get('max_creditos_activos')).').';
        if ($history['contratos_em_atraso'] > (int) CS::get('max_creditos_atraso')) $alerts[] = 'O mutuário tem '.$history['contratos_em_atraso'].' crédito(s) em atraso.';
        if ($history['contratos_abatidos'] > 0) $alerts[] = 'O mutuário tem '.$history['contratos_abatidos'].' crédito(s) abatido(s).';
        if ($history['score'] !== null && $history['score'] < $history['score_minimo']) $alerts[] = 'Score '.$history['score'].' abaixo do mínimo ('.$history['score_minimo'].').';
        return [
            'taxa' => $plan ? ($rate ?? null) : null,
            'prestacoes' => $plan ? count($plan['prestacoes']) : null,
            'prestacao' => $plan ? number_format((float) $plan['prestacoes'][0]['pagar'], 2, '.', '') : null,
            'total_a_pagar' => $total !== null ? number_format($total, 2, '.', '') : null,
            'juros_totais' => $total !== null ? number_format($total - (float) $p['montante'], 2, '.', '') : null,
            'encargo_mensal' => $monthly !== null ? number_format($monthly, 2, '.', '') : null,
            'rendimento_disponivel' => number_format($free, 2, '.', ''),
            'taxa_esforco' => $effort, 'taxa_esforco_max' => $maxEffort,
            'alertas' => $alerts, 'recomendacao' => $alerts ? 'Analisar com cuidado' : 'Favorável',
        ];
    }
}
