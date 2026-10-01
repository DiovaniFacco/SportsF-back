<?php

require_once __DIR__.'/dom_polyfill.php';

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway termina TLS e proxya as requisições: sem isso,
        // request()->ip() retorna o IP do proxy e o throttle vira um
        // balde compartilhado entre todos os usuários (auto-DoS com 429).
        $middleware->trustProxies(at: '*');

        // API não tem tela de login: guests recebem 401 JSON em vez de
        // redirect para a rota 'login' (que não existe → virava 500).
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        // 404 limpo e em PT-BR para a API (evita vazar internals do
        // route-model binding e padroniza com as demais respostas).
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Recurso não encontrado.'], 404);
            }
        });
        // 401 limpo mesmo sem header Accept: application/json (o middleware
        // auth tenta redirecionar para a rota 'login', que não existe em API).
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Não autenticado.'], 401);
            }
        });
    })->create();
