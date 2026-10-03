<?php

namespace App\Services\Localization;

use App\Models\User;
use App\Services\Platform\SetupState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * 0.4.0-rc.5 (Phase 41) — runtime application localization.
 *
 * Arabic and English are first-class product languages. This class is the
 * single authority for:
 *
 *   - which locales the product ships;
 *   - resolving the active locale for a request:
 *       authenticated user preference  →  session  →  cookie
 *         →  platform default (platform_settings `platform.locale`)  →  `en`;
 *   - persisting a language switch (session + long-lived cookie, and the
 *     user's own preference when authenticated).
 *
 * Nothing here binds a language permanently to the browser locale: the
 * Accept-Language header is deliberately NOT consulted. The user (or the
 * platform operator) chooses; the choice is stored and honored.
 */
final class LocaleManager
{
    /** Installed UI locales, in display order. English stays the fallback. */
    public const AVAILABLE = ['en', 'ar'];

    /** Session key for pre-authentication (setup/login) language choice. */
    public const SESSION_KEY = 'nexus.locale';

    /** Long-lived cookie carrying the language across sessions. */
    public const COOKIE_KEY = 'nexus_locale';

    /** platform_settings key for the platform-wide default language. */
    public const PLATFORM_DEFAULT_KEY = 'platform.locale';

    /** @return array<string,string> locale code → native display name */
    public static function available(): array
    {
        return [
            'en' => 'English',
            'ar' => 'العربية',
        ];
    }

    public static function isAvailable(string $locale): bool
    {
        return in_array($locale, self::AVAILABLE, true);
    }

    /** RTL for Arabic, LTR for everything else. */
    public static function direction(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? 'rtl' : 'ltr';
    }

    /** The platform-wide default language (Settings/setup), validated. */
    public static function platformDefault(): string
    {
        try {
            $stored = SetupState::get(self::PLATFORM_DEFAULT_KEY);
        } catch (\Throwable) {
            $stored = null;
        }

        return self::isAvailable((string) $stored) ? (string) $stored : 'en';
    }

    /**
     * Resolve the locale for the current request.
     *
     * Order: authenticated user preference → session → cookie → platform
     * default → en. Invalid values are skipped, never fatal.
     */
    public static function resolve(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User && self::isAvailable((string) $user->locale)) {
            return (string) $user->locale;
        }

        $session = $request->session()->get(self::SESSION_KEY);
        if (self::isAvailable((string) $session)) {
            return (string) $session;
        }

        $cookie = $request->cookie(self::COOKIE_KEY);
        if (self::isAvailable((string) $cookie)) {
            return (string) $cookie;
        }

        return self::platformDefault();
    }

    /**
     * Persist the user's language choice and apply it immediately.
     *
     * Authenticated: stored on the user (wins everywhere). Always: session +
     * a 1-year cookie so guests (setup wizard, login screen) keep their
     * choice before an account exists.
     */
    public static function switch(Request $request, string $locale): void
    {
        if (! self::isAvailable($locale)) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, $locale);
        Cookie::queue(self::COOKIE_KEY, $locale, 60 * 24 * 365);

        $user = $request->user();
        if ($user instanceof User) {
            $user->forceFill(['locale' => $locale])->save();
        }

        app()->setLocale($locale);
    }
}
