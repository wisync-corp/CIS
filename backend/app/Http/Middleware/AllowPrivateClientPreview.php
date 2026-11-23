<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Explicit opt-in for admin-only, read-only legacy Clientes preview.
 * Never enable in the public production compose file.
 */
final class AllowPrivateClientPreview
{
    public function handle(Request $request, Closure $next): Response
    {
        $approved = filter_var(env('CIS_CLIENTES_PREVIEW_APPROVED', false), FILTER_VALIDATE_BOOLEAN);
        $preview = filter_var(env('CIS_PRIVATE_PREVIEW', false), FILTER_VALIDATE_BOOLEAN);
        $user = $request->user();
        if (!$approved || !$preview) {
            abort(503, 'Consulta de mutuários ainda não autorizada neste ambiente.');
        }
        \App\Support\Permissions::require($user, 'ver');
        // Gestor de carteira e agente: só os mutuários da sua carteira.
        $id = $request->route('id');
        if (is_numeric($id)) \App\Support\Portfolio::requireClient($user, (int) $id);
        $numero = $request->route('numero');
        if (is_string($numero) && $numero !== '') \App\Support\Branches::requireContract($user, $numero);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        return $response;
    }
}
