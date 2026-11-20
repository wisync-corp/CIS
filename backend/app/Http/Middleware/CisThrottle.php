<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Limite de pedidos do CIS.
 *
 * O «throttle:N,1» do Laravel usa UM contador por utilizador para TODAS as rotas: ao abrir um
 * ecrã com vários pedidos (lista, totais, avisos…) o operador esgotava o limite da rota mais
 * apertada e recebia «Too Many Attempts». Aqui o contador é por utilizador E por rota, e as
 * leituras (GET) têm uma margem maior; as gravações mantêm os limites definidos em routes/api.php.
 */
final class CisThrottle extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        // Limitadores com nome (throttle:nome) seguem o comportamento normal do Laravel.
        if (is_string($maxAttempts) && !is_numeric($maxAttempts) && func_num_args() === 3) {
            return parent::handle($request, $next, $maxAttempts);
        }
        $max = (int)$maxAttempts;
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $max = max($max * 4, (int)env('CIS_THROTTLE_MIN_READS', 120));
        }
        return parent::handle($request, $next, $max, $decayMinutes, $prefix);
    }

    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $where = $request->method().' '.($route ? $route->uri() : $request->path());
        if ($user = $request->user()) {
            return sha1('u|'.$user->getAuthIdentifier().'|'.$where);
        }
        return sha1('ip|'.$request->ip().'|'.$where);
    }
}
