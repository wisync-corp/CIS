<?php
namespace App\Support\Payments;

/**
 * e-Mola (Movitel) — USSD Push C2B («pushUssdMessage») pelo gateway SOAP BCCS.
 * As credenciais (WSDL, utilizador, palavra-passe, chave e partner code) e a lista de IPs autorizados são dadas
 * pela Movitel depois do contrato. O pedido é síncrono (até 60 s à espera do PIN); o callback cobre o modo assíncrono.
 * Validar no ambiente de testes da Movitel antes de activar em produção (especificação USSD Push v1.5).
 */
final class EmolaGateway implements PaymentGateway
{
    public static function configured(): bool
    {
        $c = config('cis_subscription.emola');
        return $c['wsdl'] !== '' && $c['username'] !== '' && $c['password'] !== '' && $c['key'] !== '' && $c['partner_code'] !== '';
    }

    public function charge(string $reference, string $msisdn, float $amount, string $description): GatewayResult
    {
        if (!class_exists(\SoapClient::class)) return GatewayResult::pending('Extensão SOAP do PHP em falta no servidor: pagamento fica para confirmação manual.', 'soap');
        $c = config('cis_subscription.emola');
        $params = ['partnerCode' => $c['partner_code'], 'msisdn' => $msisdn, 'smsContent' => mb_substr($description, 0, 150),
            'transAmount' => number_format($amount, 2, '.', ''), 'transId' => $reference, 'language' => 'pt', 'refNo' => $reference, 'key' => $c['key']];
        try {
            $soap = new \SoapClient($c['wsdl'], ['connection_timeout' => 15, 'exceptions' => true, 'cache_wsdl' => WSDL_CACHE_BOTH]);
            ini_set('default_socket_timeout', '100');
            $res = $soap->__soapCall('gwOperation', [['Input' => ['username' => $c['username'], 'password' => $c['password'],
                'wscode' => 'pushUssdMessage', 'param' => array_map(fn ($k, $v) => ['name' => $k, 'value' => $v], array_keys($params), $params)]]]);
        } catch (\Throwable $e) {
            return GatewayResult::pending('Sem resposta da e-Mola. Verifique o SMS de confirmação; a WiSync confirma o pagamento.', 'timeout');
        }
        $ret = $res->Result ?? $res->return ?? $res;
        $gw = (string) ($ret->error ?? '');
        $orig = $ret->original ?? '';
        $wallet = preg_match('/<errorCode>(\w+)<\/errorCode>/', (string) $orig, $m) ? $m[1] : (string) ($ret->errorCode ?? '');
        if ($gw === '0' && in_array($wallet, ['0', '00'], true)) return GatewayResult::ok($reference, 'Pagamento e-Mola confirmado.', $wallet);
        if ($gw === '0' && $wallet === '22') return GatewayResult::pending('Pedido enviado ao telemóvel; a e-Mola confirma em seguida.', '22');
        return GatewayResult::failed(self::message($gw, $wallet), $wallet ?: $gw);
    }

    public static function message(string $gw, string $wallet): string
    {
        if ($gw !== '' && $gw !== '0') return match ($gw) {
            '6001', '6005' => 'e-Mola recusou o acesso do servidor (credenciais ou IP não autorizado).',
            '9002' => 'e-Mola: demasiados pedidos, tente daqui a pouco.',
            default => 'e-Mola indisponível (código '.$gw.').',
        };
        return match ($wallet) {
            '11' => 'O PIN e-Mola não foi introduzido a tempo.',
            '12' => 'O número não tem conta e-Mola.',
            '07' => 'Número e-Mola inválido.',
            '06' => 'Valor inválido para a e-Mola.',
            '14', '28' => 'Transacção já processada.',
            default => 'Pagamento e-Mola não concluído (código '.$wallet.').',
        };
    }
}
