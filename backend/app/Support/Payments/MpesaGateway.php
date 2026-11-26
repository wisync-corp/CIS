<?php
namespace App\Support\Payments;

use Illuminate\Support\Facades\Http;

/**
 * M-Pesa Open API (Vodacom Moçambique) — C2B single stage.
 * O token é a API key cifrada em RSA (PKCS#1) com a chave pública do portal developer.mpesa.vm.co.mz.
 * O pedido é síncrono: o cliente recebe o pedido de PIN no telemóvel e a resposta chega depois.
 */
final class MpesaGateway implements PaymentGateway
{
    public static function configured(): bool
    {
        $c = config('cis_subscription.mpesa');
        return $c['api_key'] !== '' && $c['public_key'] !== '' && $c['service_provider_code'] !== '';
    }

    public static function bearer(string $apiKey, string $publicKey): string
    {
        $pem = str_contains($publicKey, 'BEGIN PUBLIC KEY') ? $publicKey
            : "-----BEGIN PUBLIC KEY-----\n".chunk_split(preg_replace('/\s+/', '', $publicKey), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if (!$key || !openssl_public_encrypt($apiKey, $out, $key, OPENSSL_PKCS1_PADDING)) {
            throw new \RuntimeException('Chave pública M-Pesa inválida.');
        }
        return base64_encode($out);
    }

    public function charge(string $reference, string $msisdn, float $amount, string $description): GatewayResult
    {
        $c = config('cis_subscription.mpesa');
        try {
            $res = Http::timeout(95)->acceptJson()->withHeaders([
                'Authorization' => 'Bearer '.self::bearer($c['api_key'], $c['public_key']),
                'Origin' => $c['origin'],
            ])->post('https://'.$c['host'].':18352/ipg/v1x/c2bPayment/singleStage/', [
                'input_TransactionReference' => $reference,
                'input_CustomerMSISDN' => '258'.$msisdn,
                'input_Amount' => number_format($amount, 2, '.', ''),
                'input_ThirdPartyReference' => substr(strtoupper(bin2hex(random_bytes(8))), 0, 16),
                'input_ServiceProviderCode' => $c['service_provider_code'],
            ]);
        } catch (\Throwable $e) {
            // Sem resposta: o cliente pode ter pago — fica pendente para confirmação, nunca se repete às cegas.
            return GatewayResult::pending('Sem resposta da M-Pesa. Verifique o SMS de confirmação; a WiSync confirma o pagamento.', 'timeout');
        }
        $code = (string) ($res->json('output_ResponseCode') ?? $res->status());
        $desc = (string) ($res->json('output_ResponseDesc') ?? 'Resposta inesperada da M-Pesa.');
        if ($code === 'INS-0') return GatewayResult::ok((string) $res->json('output_TransactionID'), 'Pagamento M-Pesa confirmado.', $code);
        return GatewayResult::failed(self::message($code, $desc), $code);
    }

    private static function message(string $code, string $desc): string
    {
        return match ($code) {
            'INS-5' => 'O cliente cancelou o pagamento no telemóvel.',
            'INS-6' => 'Pagamento recusado pela M-Pesa.',
            'INS-9' => 'O pedido expirou: o PIN não foi introduzido a tempo.',
            'INS-10' => 'Pagamento duplicado.',
            'INS-2006' => 'Saldo M-Pesa insuficiente.',
            'INS-2051', 'INS-2057' => 'Número M-Pesa inválido.',
            default => 'M-Pesa: '.$desc,
        };
    }
}
