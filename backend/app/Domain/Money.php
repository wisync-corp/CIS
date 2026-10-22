<?php
namespace App\Domain;
/** Montantes em centavos inteiros: evita erros binários de FLOAT. */
final class Money {
    public static function parse(string $amount): int {
        $amount = trim($amount);
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $amount)) {
            throw new \InvalidArgumentException('Montante inválido; utilizar formato 1234.56');
        }
        $negative = str_starts_with($amount, '-');
        $positive = ltrim($amount, '-');
        [$units, $cents] = array_pad(explode('.', $positive, 2), 2, '0');
        $value = (int)$units * 100 + (int)str_pad($cents, 2, '0');
        return $negative ? -$value : $value;
    }
    public static function format(int $cents): string {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents),100).'.'.str_pad((string)(abs($cents)%100),2,'0',STR_PAD_LEFT);
    }
}
