<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Motor de prestações em meticais inteiros.
 *
 * Regra CIS autorizada em 16-11-2026: quaisquer valores de juros/prestações
 * positivos são arredondados para cima. O capital é sempre reconciliado
 * exactamente com o montante desembolsado; a última amortização é o
 * REMANESCENTE INTEIRO, nunca um novo arredondamento do principal.
 *
 * As fórmulas TOTAL / SIMPLE / DECLINING são extensões financeiras CIS,
 * não métodos existentes de forma autónoma no Contractos.frm.
 */
final class LegacyContractPricing
{
    public static function upwards(float $amount): int
    {
        if (!is_finite($amount) || $amount < 0 || $amount > 1000000000) {
            throw ValidationException::withMessages(['montante'=>'Resultado financeiro inválido.']);
        }
        // Tolerância mínima para desvios binários em cálculos de quocientes.
        return (int)ceil($amount - 1e-9);
    }

    public static function plan(float $principal, float $taxa, string $tipo, string $modalidade,
        int $meses, string $dataInicio, ?string $estrategia = null,
        ?string $prazo = null, int $periodoDias = 30): array
    {
        if (!is_finite($principal) || $principal <= 0 || $principal > 100000000
            || abs($principal-round($principal)) > 1e-8
            || !is_finite($taxa) || $taxa < 0 || $taxa > 100
            || $meses < 0 || $meses > 3 || !in_array($periodoDias,[15,30],true)) {
            throw ValidationException::withMessages(['montante'=>
                'Montante inteiro positivo, taxa e prazo válidos são obrigatórios.']);
        }
        $estrategia ??= ContractCalculationStrategy::supportedFor($tipo);
        if (!$estrategia || !ContractCalculationStrategy::compatible($tipo,$estrategia)) {
            throw ValidationException::withMessages(['estrategia_calculo'=>
                'A estratégia não é compatível com o tipo de juro.']);
        }
        if (($tipo === 'Variável' && $modalidade !== 'Uma Parcela')
            || ($tipo === 'Composto' && !in_array($modalidade,['Mensal'],true))
            || !in_array($modalidade,['Semanal','Mensal','Uma Parcela'],true)) {
            throw ValidationException::withMessages(['modalidade'=>'Modalidade incompatível com esta estratégia.']);
        }
        $inicio=CarbonImmutable::createFromFormat('!Y-m-d',$dataInicio);
        if (!$inicio || $inicio->format('Y-m-d')!==$dataInicio) {
            throw ValidationException::withMessages(['data_inicio'=>'Data de início inválida.']);
        }
        $prazo ??= $meses.' '.($meses===1?'Mês':'Meses');
        $weeklyTerm = preg_match('/^([123]) Semanas?$/u',$prazo,$matches)===1;
        $expectedMonths=$weeklyTerm?0:(int)explode(' ',$prazo)[0];
        if ($meses!==$expectedMonths || (!$weeklyTerm && !in_array($prazo,['1 Mês','2 Meses','3 Meses'],true))
            || ($weeklyTerm && $periodoDias===15 && (int)$matches[1]>2)) {
            throw ValidationException::withMessages(['prazo'=>'Prazo e período não são compatíveis.']);
        }
        // Regra semanal CIS: semanas de 7 dias. Num prazo mensal, o último
        // vencimento semanal ajusta-se ao dia final do calendário.
        $fimDate=$weeklyTerm
            ? $inicio->addDays(((int)$matches[1])*7)
            : $inicio->addMonthsNoOverflow($meses);
        $fim=$fimDate->format('Y-m-d');
        $dias=(int)$inicio->diffInDays($fimDate);
        $k=$tipo==='Variável'||$modalidade==='Uma Parcela'?1:
            ($modalidade==='Semanal'?(int)ceil($dias/7):$meses);
        if ($weeklyTerm && $modalidade==='Mensal') {
            throw ValidationException::withMessages(['modalidade'=>'Não existem mensalidades num prazo de semanas.']);
        }
        if ($tipo==='Composto' && $modalidade==='Semanal') {
            throw ValidationException::withMessages(['modalidade'=>
                'Para juros compostos semanais falta aprovação da conversão da taxa de referência de 15/30 dias.']);
        }
        $P=(int)$principal;
        if ($P<$k) {
            throw ValidationException::withMessages(['montante'=>
                'O capital não chega para amortizar pelo menos 1 MT por prestação.']);
        }
        $r=$taxa/100;
        $totalInterest=match($estrategia) {
            ContractCalculationStrategy::FIXED_AMORTIZATION,
            ContractCalculationStrategy::TOTAL => self::upwards($P*$r),
            default => null,
        };
        $price=$estrategia===ContractCalculationStrategy::PRICE
            ? self::upwards($r==0.0?$P/$k:
                ($P*$r*pow(1+$r,$k))/(pow(1+$r,$k)-1))
            : null;
        $totalDue=$estrategia===ContractCalculationStrategy::TOTAL
            ? $P+$totalInterest : null;
        $perPayment=$totalDue!==null?self::upwards($totalDue/$k):null;
        $perCapital=self::upwards($P/$k);
        $perFixedInterest=$totalInterest!==null?self::upwards($totalInterest/$k):null;
        $saldo=$P;
        $paidInterest=0;
        $paidTotal=0;
        $rows=[];
        for ($i=1;$i<=$k;$i++) {
            $last=$i===$k;
            $capital=$saldo;
            $amortizacao=$last?$saldo:min($saldo,$perCapital);
            $juros=0;
            $pagar=0;
            switch ($estrategia) {
                case ContractCalculationStrategy::VARIABLE_COLLECTION:
                    $juros=0; $pagar=$amortizacao; break;
                case ContractCalculationStrategy::FIXED_AMORTIZATION:
                    $juros=$last?($totalInterest-$paidInterest):min($perFixedInterest,$totalInterest-$paidInterest);
                    $pagar=$amortizacao+$juros; break;
                case ContractCalculationStrategy::TOTAL:
                    $pagar=$last?($totalDue-$paidTotal):min($perPayment,$totalDue-$paidTotal);
                    $juros=$pagar-$amortizacao;
                    break;
                case ContractCalculationStrategy::SIMPLE:
                    // Juros simples por prestação sobre o capital ORIGINAL.
                    $juros=self::upwards($P*$r);
                    $pagar=$amortizacao+$juros;
                    break;
                case ContractCalculationStrategy::DECLINING:
                    // Juros sobre o saldo ANTES da amortização desta prestação.
                    $juros=self::upwards($saldo*$r);
                    $pagar=$amortizacao+$juros;
                    break;
                case ContractCalculationStrategy::PRICE:
                    $juros=self::upwards($saldo*$r);
                    $pagar=$last?$saldo+$juros:$price;
                    $amortizacao=$last?$saldo:$pagar-$juros;
                    break;
            }
            if ($amortizacao<=0 || $amortizacao>$saldo || $juros<0 || $pagar<0
                || $pagar!==$amortizacao+$juros) {
                throw ValidationException::withMessages(['montante'=>
                    'A distribuição da estratégia gerou prestação inválida. Operação cancelada.']);
            }
            $saldo-=$amortizacao;
            $paidInterest+=$juros;
            $paidTotal+=$pagar;
            $rows[]=[
                'parcela'=>$i,
                'vencimento'=>$last?$fim:($modalidade==='Semanal'?$inicio->addDays(7*$i)->format('Y-m-d'):$inicio->addMonthsNoOverflow($i)->format('Y-m-d')),
                'capital'=>$capital,
                'amortizacao'=>$amortizacao,
                'juros'=>$juros,
                'pagar'=>$pagar,
            ];
        }
        if ($saldo!==0 || array_sum(array_column($rows,'amortizacao'))!==$P
            || $paidTotal!==$P+$paidInterest) {
            throw ValidationException::withMessages(['montante'=>
                'Não foi possível reconciliar capital, juros e prestações.']);
        }
        return [
            'data_final'=>$fim,'modalidade'=>$modalidade,'prestacoes'=>$rows,
            'total_juros'=>$paidInterest,'total_pagar'=>$paidTotal,
            'estrategia_calculo'=>$estrategia,'prazo'=>$prazo,
        ];
    }
}
