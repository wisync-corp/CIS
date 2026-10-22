<?php
require __DIR__.'/../app/Domain/Money.php';
require __DIR__.'/../app/Domain/DistribuicaoPagamento.php';
use App\Domain\Money;
use App\Domain\DistribuicaoPagamento;
function ok(bool $valid, string $name): void { if (!$valid) throw new RuntimeException($name); }
ok(Money::parse('2500.00') === 250000, 'parse');
ok(Money::format(150123) === '1501.23', 'format');
$r = DistribuicaoPagamento::validar(150000,50000,80000,20000,200000,80000,20000);
ok($r['saldo_amortizacao'] === 150000 && $r['saldo_juros'] === 0 && $r['saldo_multa'] === 0, 'distribution');
try { DistribuicaoPagamento::validar(150000,150000,1000,0,200000,80000,20000); throw new RuntimeException('Expected rejection'); }
catch (DomainException $e) {}
echo "Smoke tests OK\n";

// Regression: a legacy Extracto movement marked 'Receita de Juros' can include principal repayment.
constLegacyReconciliation();
function constLegacyReconciliation(): void {
    $r = \App\Domain\DistribuicaoPagamento::validar(1250000, 1000000, 250000, 0, 1000000, 250000, 0);
    if ($r['juros'] !== 250000 || $r['saldo_amortizacao'] !== 0) throw new RuntimeException('Legacy reconciliation failure');
}
