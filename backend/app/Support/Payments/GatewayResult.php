<?php
namespace App\Support\Payments;

/** Resposta de uma operadora: confirmado, pendente (assíncrono/manual) ou falhado. */
final class GatewayResult
{
    public function __construct(
        public readonly string $estado,
        public readonly string $mensagem,
        public readonly ?string $codigo = null,
        public readonly ?string $transacao = null,
    ) {}

    public static function ok(string $tx, string $msg = 'Pagamento confirmado pela operadora.', ?string $code = null): self { return new self('confirmado', $msg, $code, $tx); }
    public static function pending(string $msg, ?string $code = null): self { return new self('pendente', $msg, $code); }
    public static function failed(string $msg, ?string $code = null): self { return new self('falhado', $msg, $code); }
}
