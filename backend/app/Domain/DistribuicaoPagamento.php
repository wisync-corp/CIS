<?php
namespace App\Domain;
/**
 * Decompõe valores JÁ autorizados por uma regra financeira validada.
 * Não define prioridade de imputação (ainda em investigação no VBA).
 */
final class DistribuicaoPagamento {
    public static function validar(
        int $recebido, int $amortizacao, int $juros, int $multa,
        int $saldoAmortizacao, int $saldoJuros, int $saldoMulta
    ): array {
        foreach (compact('recebido','amortizacao','juros','multa','saldoAmortizacao','saldoJuros','saldoMulta') as $key=>$value) {
            if ($value < 0) throw new \InvalidArgumentException("$key não pode ser negativo");
        }
        if ($amortizacao > $saldoAmortizacao || $juros > $saldoJuros || $multa > $saldoMulta) {
            throw new \DomainException('Pagamento excede saldo de um dos componentes');
        }
        if ($recebido !== $amortizacao+$juros+$multa) {
            throw new \DomainException('Soma de componentes diferente do valor recebido');
        }
        return [
            'recebido' => $recebido, 'amortizacao' => $amortizacao,
            'juros' => $juros, 'multa' => $multa,
            'saldo_amortizacao' => $saldoAmortizacao-$amortizacao,
            'saldo_juros' => $saldoJuros-$juros,
            'saldo_multa' => $saldoMulta-$multa,
        ];
    }
}
