<?php
namespace App\Support;

/**
 * Encargos do crédito (Definições › Encargos do crédito e › Cobrança e juros de mora):
 *  - taxa de operação: % do capital (com mínimo), descontada no desembolso;
 *  - taxa de renovação: % do capital renovado;
 *  - tratamento dos encargos na renovação: cobrar já ou capitalizar na nova prestação;
 *  - mora automática: regra da empresa aplicada às prestações em atraso sem taxa contratual;
 *  - categorias de finalidade do crédito.
 * Valores em cêntimos; contratos CIS (meticais inteiros) arredondam para cima.
 */
final class CreditCharges
{
    private static function up(float $cents, bool $whole): int
    {
        return $whole ? (int) ceil(round($cents / 100, 6)) * 100 : (int) round($cents);
    }

    /** Taxa de operação sobre o capital (cêntimos). 0 quando não configurada. */
    public static function operationFee(int $capitalC, bool $whole = true): int
    {
        $pct = CompanySettings::num('taxa_operacao');
        $min = (int) round(CompanySettings::num('taxa_operacao_minima') * 100);
        if ($pct <= 0 && $min <= 0) return 0;
        $fee = max(self::up($capitalC * $pct / 100, $whole), $whole ? (int) ceil($min / 100) * 100 : $min);
        return min($fee, $capitalC);
    }

    public static function renewalFee(int $principalC, bool $whole): int
    {
        $pct = CompanySettings::num('taxa_renovacao');
        return $pct > 0 ? self::up($principalC * $pct / 100, $whole) : 0;
    }

    public static function renewalTreatment(?string $requested = null): string
    {
        $v = $requested ?: CompanySettings::get('encargos_renovacao');
        return in_array($v, ['cobrar', 'capitalizar'], true) ? $v : 'cobrar';
    }

    public static function renewalLateLimit(): int
    {
        return (int) CompanySettings::num('renovacao_atraso_max_dias');
    }

    /** Regra de mora da empresa (formato LateCharge) ou null se a mora automática estiver desligada. */
    public static function companyLateRule(): ?array
    {
        if (CompanySettings::get('penalidades_automaticas') !== '1') return null;
        $rate = CompanySettings::num('taxa_diaria_mora');
        if ($rate <= 0) return null;
        $rule = ['tipo' => 'percentual_periodico', 'taxa' => rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.'),
            'base' => CompanySettings::get('mora_base') === 'capital' ? 'capital' : 'saldo', 'periodicidade' => 'diaria',
            'carencia' => (int) CompanySettings::num('tolerancia_dias'), 'origem' => 'empresa'];
        $lim = (int) CompanySettings::num('limite_mensal_dias_mora');
        if ($lim > 0 && $lim < 31) $rule['limite_mensal'] = $lim;
        $cap = CompanySettings::num('mora_tecto_pct');
        if ($cap > 0) $rule['tecto_pct'] = rtrim(rtrim(number_format($cap, 4, '.', ''), '0'), '.');
        return $rule;
    }

    public static function describeLateRule(?array $r): string
    {
        if (!$r) return 'Mora automática desligada: o gestor escolhe a regra em cada cobrança.';
        $t = str_replace('.', ',', $r['taxa']).'% por dia sobre o '.($r['base'] === 'capital' ? 'capital em dívida' : 'valor em dívida');
        if ($r['carencia'] > 0) $t .= ', depois de '.$r['carencia'].' dia(s) de tolerância';
        if (isset($r['limite_mensal'])) $t .= ', até '.$r['limite_mensal'].' dias por mês';
        if (isset($r['tecto_pct'])) $t .= ', no máximo '.str_replace('.', ',', $r['tecto_pct']).'% do valor em dívida';
        return 'Juros de mora automáticos: '.$t.'.';
    }

    /** @return list<string> */
    public static function purposes(): array
    {
        $list = array_values(array_filter(array_map('trim', explode(',', CompanySettings::get('finalidades'))), fn ($v) => $v !== ''));
        return $list ?: ['Outra'];
    }
}
