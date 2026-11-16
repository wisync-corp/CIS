<?php

namespace App\Services;

/**
 * Compatibilidade financeira definida para o CIS.
 * As opções além das duas fórmulas do VBA são regras novas solicitadas pelo
 * operador: não devem ser apresentadas como transcrição do Contractos.frm.
 */
final class ContractCalculationStrategy
{
    public const DECLINING = 'Amortização fixa com juro decrescente';
    public const FIXED_AMORTIZATION = 'Amortização fixa com juro fixo';
    public const TOTAL = 'Prestação fixa pelo total';
    public const SIMPLE = 'Prestação fixa simples';
    public const PRICE = 'Tabela Price';
    public const VARIABLE_COLLECTION = 'Juro variável na cobrança';

    public const OPTIONS = [
        self::DECLINING,
        self::FIXED_AMORTIZATION,
        self::TOTAL,
        self::SIMPLE,
        self::PRICE,
        self::VARIABLE_COLLECTION,
    ];

    public const COMPATIBILITY = [
        'Fixo' => [self::FIXED_AMORTIZATION, self::TOTAL, self::SIMPLE],
        'Composto' => [self::DECLINING, self::PRICE],
        'Variável' => [self::VARIABLE_COLLECTION],
    ];

    public static function supportedFor(string $type): ?string
    {
        return match ($type) {
            'Fixo' => self::FIXED_AMORTIZATION,
            'Composto' => self::PRICE,
            'Variável' => self::VARIABLE_COLLECTION,
            default => null,
        };
    }

    public static function forType(string $type): array
    {
        return self::COMPATIBILITY[$type] ?? [];
    }

    public static function compatible(string $type, string $strategy): bool
    {
        return in_array($strategy, self::forType($type), true);
    }
}
