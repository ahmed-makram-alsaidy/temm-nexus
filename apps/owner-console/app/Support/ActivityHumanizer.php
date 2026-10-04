<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * 0.6.0 Phase A — the activity humanizer (audit §A6).
 *
 * Turns raw audit action verbs (`WIZARD_SOURCE_CONNECTED`) into short
 * human sentences ("Connected a source") for product surfaces. The raw
 * verb is never destroyed: audit detail views and technical metadata
 * keep it, and `raw()` is available wherever fidelity is required.
 *
 * Translations live in lang/{en,ar}/activity.php. Unknown verbs fall
 * back to Title Case so new audit actions are readable the moment they
 * appear, without breaking translation completeness.
 */
class ActivityHumanizer
{
    /**
     * Human sentence for an audit action, translated.
     *
     * @param  string  $action  Raw audit verb, e.g. WIZARD_SOURCE_CONNECTED.
     */
    public static function humanize(string $action): string
    {
        $key = 'activity.'.Str::lower(trim($action));

        if (trans()->has($key)) {
            return __($key);
        }

        return self::fallback($action);
    }

    /** Title-Case fallback for verbs with no dictionary entry. */
    public static function fallback(string $action): string
    {
        return Str::title(str_replace('_', ' ', Str::lower(trim($action))));
    }

    /** The raw verb — for audit detail views and technical metadata. */
    public static function raw(string $action): string
    {
        return $action;
    }
}
