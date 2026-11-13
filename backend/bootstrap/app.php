<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware) {
        // Override Laravel's default route('login') callback: API-only CIS has no web login route.
        // The exception renderer below returns JSON 401 for all /api/* requests.
        $middleware->redirectGuestsTo('/api/auth/login');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A API é stateless e não tem uma rota web chamada "login".
        // Sem isto, requests sem token e Accept: */* podem causar 500
        // quando o Laravel tenta gerar route('login').
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Não autenticado.'], 401);
            }
            return null;
        });
    })
    ->create();
