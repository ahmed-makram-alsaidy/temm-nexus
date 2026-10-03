<?php

namespace App\Providers;

use App\Models\User;
use App\Services\ControlPlane\Connectors\ConnectorDiscovery;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 27D — the connector registry is a singleton; discovery from
        // first-party packages (app/Connectors/*) runs lazily on first use
        // and is idempotent within a request lifecycle.
        $this->app->singleton(ConnectorRegistry::class);
    }

    public function boot(): void
    {
        // Phase 27J.1 — discover first-party connector packages once per boot.
        ConnectorDiscovery::discover();

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Failed::class, function ($e) {
            \Illuminate\Support\Facades\Log::warning('AUTH_FAILED', ['user' => $e->user?->id, 'email' => is_string($e->credentials['email'] ?? null) ? $e->credentials['email'] : 'MISSING']);
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Validated::class, function ($e) {
            \Illuminate\Support\Facades\Log::warning('AUTH_VALIDATED', ['user' => $e->user?->id]);
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function ($e) {
            \Illuminate\Support\Facades\Log::warning('AUTH_LOGIN_OK', ['user' => $e->user?->id]);
        });
        // General API throttle: 60 req/min per IP (routes reference 'throttle:api').
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // Login throttling is enforced by Filament's own Livewire rate limiter
        // (5 attempts — vendor-verified in filament/filament Auth/Pages/Login.php).
        // No parallel custom limiter: a dead limiter would be a false control.

        // Phase 26E — platform-wide password policy. No default password ever
        // exists; setup bootstrap and admin recovery both flow through here.
        // Deterministic rules only (no network-backed compromise checks, so a
        // fresh install never needs outbound internet to bootstrap).
        \Illuminate\Validation\Rules\Password::defaults(
            fn () => \Illuminate\Validation\Rules\Password::min(12)
                ->letters()->numbers()->mixedCase()->symbols()
        );

        // Internal dashboards: infrastructure owners only.
        Gate::define('viewPulse', fn (?User $user) => $user !== null && (bool) $user->is_admin);

        // Guests hitting auth-protected web routes (e.g. file downloads) get a
        // clean redirect to the owner login instead of a 500 (no 'login' route).
        Authenticate::redirectUsing(fn () => '/admin/login');
    }
}
