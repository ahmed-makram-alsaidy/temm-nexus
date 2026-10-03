<?php

namespace App\Http\Middleware;

use App\Services\Platform\SetupState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 26D — first-run gate.
 *
 * While the platform is uninitialized every surface is redirected to /setup,
 * and once initialized /setup is locked. A minimal allowlist keeps machine
 * health probes working so the stack can be observed before/after bootstrap:
 *   /setup*        — the wizard itself
 *   /up            — Laravel built-in health endpoint
 *   /api/health    — platform health JSON (unauthenticated, throttled)
 *   /admin/login   — Filament's login route path is also blocked while
 *                    uninitialized via the panel's own middleware below; we do
 *                    not add it here because Filament renders it inside the
 *                    /admin panel prefix which IS redirected.
 */
class EnsurePlatformInitialized
{
    protected array $allowed = [
        'setup',
        'setup/*',
        'up',
        'api/health',
        // 0.4.0-rc.5 (C.4): the language switch must work BEFORE a user or
        // platform default exists — the setup wizard and login screen are
        // exactly the surfaces that need it. The locale endpoint validates
        // its input and only touches session/cookie/user preference.
        'locale',
        // Local webhook test fixture: token-authenticated machine endpoint
        // (throttled), independent of platform bootstrap state.
        'cp-webhook-fixture/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $initialized = SetupState::initialized();

        if (! $initialized && ! $request->is(...$this->allowed)) {
            return redirect()->to('/setup');
        }

        if ($initialized && $request->is('setup', 'setup/*')) {
            // Setup is locked after completion (26D.5).
            return redirect()->to('/admin');
        }

        return $next($request);
    }
}
