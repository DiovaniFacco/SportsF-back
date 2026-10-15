<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Throttle nomeado para as rotas públicas de auth.
        // Por IP (rajadas) + por conta/dia (força-bruta distribuída contra um e-mail).
        RateLimiter::for('auth', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perDay(60)->by('auth:'.strtolower((string) ($request->input('email') ?? $request->ip()))),
            ];
        });

        // Throttle dedicado ao forgot-password: evita usar a API para
        // bombardear caixas de entrada alheias com e-mails de reset.
        RateLimiter::for('forgot', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        // Coleta de acessos (analytics): endpoint público com token opcional.
        // Limite generoso (uma página normal dispara poucos eventos) mas suficiente
        // para conter abuso/scraping do endpoint de escrita.
        RateLimiter::for('analytics', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
