<?php

use App\Http\Middleware\EnsurePlatformInitialized;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API-first template: trust the reverse proxy (Caddy) for client IP /
        // scheme so rate limiting, pagination URLs, and secure cookies are correct.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));
        // Phase 26D — first-run gate: everything redirects to /setup until the
        // platform is initialized; /setup locks afterwards. Machine health
        // probes (/up, /api/health) stay reachable.
        $middleware->web(append: [EnsurePlatformInitialized::class]);
        // 0.4.0-rc.5 (Phase 41) — runtime locale (user pref → session →
        // cookie → platform default). Appended last so the session has
        // started before resolution reads it.
        $middleware->web(append: [\App\Http\Middleware\SetRequestLocale::class]);
        $middleware->api(append: [EnsurePlatformInitialized::class]);
        // Stateless machine endpoints carry their own auth (per-function auth
        // modes, fixture tokens, HMAC-signed URLs) and must accept
        // server-to-server POSTs without a session CSRF token.
        $middleware->validateCsrfTokens(except: [
            'f/*',
            'cp-webhook-fixture/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
