<?php

namespace App\Http\Controllers;

use App\Services\Localization\LocaleManager;
use Illuminate\Http\Request;

/**
 * 0.4.0-rc.5 (Phase 41) — language switch endpoint.
 *
 * Public (no auth): the setup wizard and the login screen must be usable in
 * Arabic before any user exists — the choice lands in session + cookie.
 * Authenticated, the choice is also persisted on the user, where it wins
 * over everything else on later requests.
 */
class LocaleController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', LocaleManager::AVAILABLE)],
        ]);

        LocaleManager::switch($request, $data['locale']);

        return redirect()->back();
    }
}
