<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class LegacyContractPricing
{
    // VB Round(value, 2) = empate para o par. Os valores são arredondados
    // em cada etapa, como em cmb_adicionar_Click do Contractos.frm.
    public static function round(float $value): float
    {
        return round($value, 2, PHP_ROUND_HALF_EVEN);
    }

    public static function plan(float $principal, float $taxa, string $tipo, string $modalidade,
        int $meses, string $dataInicio): array
    {
        if ($principal <= 0 || $principal > 100000000 || $taxa < 0 || $taxa > 100
            || !in_array($tipo, ['Fixo','Variável','Composto'], true) || $meses < 1 || $meses > 3) {
            throw ValidationException::withMessages(['contracto'=>'Condições do contrato inválidas.']);
        }

        $inicio = CarbonImmutable::createFromFormat('!Y-m-d', $dataInicio);
        if (!$inicio || $inicio->format('Y-m-d') !== $dataInicio) {
            throw ValidationException::withMessages(['data_inicio'=>'Data de início inválida.']);
        }
        $final = $inicio->addMonthsNoOverflow($meses)->format('Y-m-d');
        $modo = $tipo === 'Variável' ? 'Uma Parcela'
            : ($tipo === 'Composto' ? 'Mensal' : $modalidade);
        $k = $tipo === 'Variável' || $modo === 'Uma Parcela' ? 1 : $meses;
        $r = $taxa / 100.0;
        $totalJuros = $tipo === 'Fixo' ? self::round($principal * $r) : 0.0;
        $base = $tipo === 'Composto'
            ? self::round($r == 0.0 ? $principal / $k
                : ($principal * $r * pow(1+$r,$k)) / (pow(1+$r,$k)-1)) : 0.0;
        $saldo = self::round($principal);
        $jurosDistribuidos = 0.0;
        $rows = [];
        for ($i=1; $i<=$k; $i++) {
            $vencimento = ($i === $k ? $final
                : $inicio->addMonthsNoOverflow($i)->format('Y-m-d'));
            if ($tipo === 'Variável') {
                $capital = self::round($principal);
                $amortizacao = $capital;
                $juros = 0.0;
                $pagar = $capital;
            } elseif ($tipo === 'Composto') {
                $capital = self::round($saldo);
                $juros = self::round($capital * $r);
                if ($i === $k) {
                    $amortizacao = self::round($saldo);
                    $pagar = self::round($amortizacao + $juros);
                } else {
                    $pagar = $base;
                    $amortizacao = self::round($pagar - $juros);
                }
                $saldo = self::round($saldo - $amortizacao);
            } else {
                $capital = self::round($saldo);
                $amortizacao = $i === $k ? self::round($saldo)
                    : self::round($principal / $k);
                if ($i === $k) {
                    $juros = self::round($totalJuros - $jurosDistribuidos);
                } else {
                    $juros = self::round($totalJuros / $k);
                    $jurosDistribuidos = self::round($jurosDistribuidos + $juros);
                }
                $pagar = self::round($amortizacao + $juros);
                $saldo = self::round($saldo - $amortizacao);
            }
            if (abs($saldo) < 0.01) $saldo = 0;
            if (!is_finite($capital) || !is_finite($amortizacao)
                || !is_finite($juros) || !is_finite($pagar) ||
                $amortizacao < 0 || $pagar < 0 || $saldo < 0) {
                throw ValidationException::withMessages(['montante'=>'O cálculo produziu valores inválidos.']);
            }
            // O esquema ORIGINAL tem Capital e Amortização do tipo INT, NÃO DECIMAL.
            // Guardar centavos nessa coluna truncaria dinheiro. Não alterar o esquema sem
            // autorização específica. Impedir contratos que exigiriam perda de centavos.
            if (abs($capital - round($capital)) > 0.00001 ||
                abs($amortizacao - round($amortizacao)) > 0.00001) {
                throw ValidationException::withMessages(['montante'=>
                    'Esta combinação exige centavos em Capital/Amortização, que são INT no MySQL legado. Não é seguro gravar sem migração autorizada para DECIMAL.']);
            }
            $rows[] = [
                'parcela'=>$i, 'vencimento'=>$vencimento,
                'capital'=>$capital, 'amortizacao'=>$amortizacao,
                'juros'=>$juros, 'pagar'=>$pagar,
            ];
        }
        return ['data_final'=>$final, 'modalidade'=>$modo, 'prestacoes'=>$rows];
    }
}
