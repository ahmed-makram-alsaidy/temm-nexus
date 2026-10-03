<?php

namespace App\Http\Middleware;

use App\Services\Localization\LocaleManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 0.4.0-rc.5 (Phase 41) — apply the resolved UI locale to every request.
 *
 * Runs inside the `web` group and on the Filament panel, AFTER the session
 * starts (locale resolution may read session/cookie/user state). The
 * resolved locale drives translations, date formatting, RTL/LTR direction
 * (Filament derives `dir` from `filament-panels::layout.direction`), and
 * the assistant's default response language.
 */
class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            app()->setLocale(LocaleManager::resolve($request));
        } catch (\Throwable) {
            // Localization must never take a page down; config default stands.
        }

        return $next($request);
    }
}
