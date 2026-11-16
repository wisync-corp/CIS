<?php

namespace App\Services;

/**
 * A lista visual inclui as cinco estratégias fornecidas pelo operador.
 * A rotina Contractos.frm só confirma matematicamente dois mecanismos:
 * juros fixos totais distribuídos com amortização igual e prestação
 * Price para COMPOSTO. Variável é apurado no módulo Cobrança.
 */
final class ContractCalculationStrategy
{
    public const FIXED_AMORTIZATION = 'Amortização fixa com juro fixo';
    public const PRICE = 'Tabela Price';
    public const VARIABLE_COLLECTION = 'Juro variável na cobrança';

    public const OPTIONS = [
        'Amortização fixa com juro decrescente',
        self::FIXED_AMORTIZATION,
        'Prestação fixa pelo total',
        'Prestação fixa simples',
        self::PRICE,
        self::VARIABLE_COLLECTION,
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

    public static function compatible(string $type, string $strategy): bool
    {
        return self::supportedFor($type) === $strategy;
    }
}
