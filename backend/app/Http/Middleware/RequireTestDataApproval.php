<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail closed for queries against the isolated financial test replica.
 * Must never be treated as a replacement for MySQL SELECT-only grants,
 * VPN, tenant authorization, and private network controls.
 */
class RequireTestDataApproval
{
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = filter_var(env('CIS_FINANCIAL_TEST_APPROVED', false), FILTER_VALIDATE_BOOLEAN);
        $env = (string) config('app.env');
        $database = (string) env('LEGACY_DB_DATABASE', '');
        $name = strtolower($database);

        if (!$enabled || !in_array($env, ['local', 'testing', 'staging'], true)
            || $database === ''
            || !preg_match('/(?:test|teste|staging|sandbox|replica)/i', $name)
            || preg_match('/(?:prod|production|producao|produção)/iu', $name)) {
            abort(503, 'Consulta financeira indisponível: ambiente de testes não aprovado.');
        }

        return $next($request);
    }
}
