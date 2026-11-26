<?php
namespace App\Support\Payments;

/** Cobrança C2B (cliente → WiSync) de uma subscrição. $msisdn: 9 dígitos sem 258. */
interface PaymentGateway
{
    public function charge(string $reference, string $msisdn, float $amount, string $description): GatewayResult;
}
