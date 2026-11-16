<?php

namespace App\Services;

/**
 * Compila INSERT na tabela legada Contractos sem interpretar pontos dos
 * nomes de colunas como separadores de tabela (ex.: `M. Pagamento`).
 * Nomes de colunas vêm apenas do código; valores são sempre parametrizados.
 */
final class LegacyContractInsert
{
    public static function statement(array $attributes): array
    {
        if ($attributes === []) {
            throw new \InvalidArgumentException('Contrato sem campos para gravar.');
        }
        $columns = array_keys($attributes);
        foreach ($columns as $column) {
            if (!is_string($column) || $column === '' || str_contains($column, "\0")) {
                throw new \InvalidArgumentException('Identificador SQL inválido.');
            }
        }
        $quoted = array_map(
            static fn (string $column): string => '`'.str_replace('`', '``', $column).'`',
            $columns
        );
        $sql = 'INSERT INTO `Contractos` ('.implode(', ', $quoted).') VALUES ('.
            implode(', ', array_fill(0, count($columns), '?')).')';
        return [$sql, array_values($attributes)];
    }
}
