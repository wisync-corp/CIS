<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consultas de contratos/prestações em modo privado, estritamente SELECT.
 * Não concede acesso financeiro por escrita nem substitui homologação financeira.
 */
final class AllowPrivateContractsPreview
{
    public function handle(Request $request, Closure $next): Response
    {
        $approved = filter_var(env('CIS_CONTRACTS_READ_APPROVED', false), FILTER_VALIDATE_BOOLEAN);
        $private = filter_var(env('CIS_PRIVATE_PREVIEW', false), FILTER_VALIDATE_BOOLEAN);
        if (!$private || !$approved) {
            abort(503, 'Consulta de contratos desactivada até autorização no ambiente privado.');
        }
        $user = $request->user();
        \App\Support\Permissions::require($user, 'ver');
        // Contratos de outra agência ficam fora do alcance de quem está numa agência.
        $numero = $request->route('numero');
        if (is_string($numero) && $numero !== '') \App\Support\Branches::requireContract($user, $numero);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        return $response;
    }
}
