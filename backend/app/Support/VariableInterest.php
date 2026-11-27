<?php
namespace App\Support;

use App\Services\LegacyContractPricing;

/**
 * Juro variável à data: capital × taxa diária × dias desde o desembolso (Data de Inicio).
 * A mesma conta da cobrança (ContractCollectionController::quote), para que o Extracto, a Visão geral
 * e os avisos mostrem o valor actualizado em vez do valor gravado na criação do contrato.
 *  - contratos CIS (Estratégia de Cálculo preenchida): meticais inteiros, para cima; valor = Amortização + juros;
 *  - contratos históricos do VBA: 2 casas, ROUND; valor = Capital (ou Quantia Creditada) + juros.
 */
final class VariableInterest
{
    public static function isVariable(object $r): bool
    {
        return in_array(trim((string) ($r->{'Tipo de Juro'} ?? '')), ['Variável', 'Variavel'], true);
    }

    private static function isCis(object $r): bool
    {
        $s = $r->{'Estratégia de Cálculo'} ?? null;
        return $s !== null && trim((string) $s) !== '';
    }

    public static function days(object $r, string $today): int
    {
        $start = substr((string) ($r->{'Data de Inicio'} ?? ''), 0, 10);
        if ($start === '') return 0;
        return max(0, (int) \Carbon\CarbonImmutable::parse($start)->diffInDays(\Carbon\CarbonImmutable::parse($today), false));
    }

    /** @return array{juros: float, valor: float, dias: int} em MT, à data $today */
    public static function toDate(object $r, string $today): array
    {
        $days = self::days($r, $today);
        $rate = (float) ($r->{'% de Juro'} ?? 0);
        if (self::isCis($r)) {
            $interest = LegacyContractPricing::upwards((int) $r->Capital * $rate / 100 * $days);
            return ['juros' => (float) $interest, 'valor' => (float) ((int) ($r->{'Amortização'} ?? 0) + $interest), 'dias' => $days];
        }
        $capC = (int) round(((float) ($r->Capital ?? 0)) * 100);
        if ($capC <= 0) $capC = (int) round(((float) ($r->{'Quantia Creditada'} ?? 0)) * 100);
        $intC = (int) round($capC * $rate / 100 * $days);
        return ['juros' => $intC / 100, 'valor' => ($capC + $intC) / 100, 'dias' => $days];
    }

    /** Expressão SQL do valor a pagar à data (juro variável actualizado; restantes = Valor a Pagar). */
    public static function dueSql(string $today): string
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) === 1, 500);
        $d = "GREATEST(0, DATEDIFF('$today', `Data de Inicio`))";
        $cis = "(`Estratégia de Cálculo` IS NOT NULL AND TRIM(`Estratégia de Cálculo`) <> '')";
        $cap = "(CASE WHEN COALESCE(`Capital`,0) > 0 THEN `Capital` ELSE COALESCE(`Quantia Creditada`,0) END)";
        $var = "(CASE WHEN $cis THEN COALESCE(`Amortização`,0) + CEIL(`Capital` * `% de Juro` / 100 * $d - 0.000000001)"
            ." ELSE $cap + ROUND($cap * `% de Juro` / 100 * $d, 2) END)";
        return "(CASE WHEN TRIM(COALESCE(`Tipo de Juro`,'')) IN ('Variável','Variavel') THEN $var ELSE COALESCE(`Valor a Pagar`,0) END)";
    }
}
