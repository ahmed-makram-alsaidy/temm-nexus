<?php

namespace App\Support;

use Throwable;

/**
 * 0.6.0 Phase A — the reusable error payload (audit §A7).
 *
 * Every user-facing failure follows ONE pattern:
 *
 *   WHAT FAILED  — short plain-language title
 *   WHAT IT MEANS / WHAT TO DO — one supporting sentence + optional action
 *   Technical details ▸ — codes, HTTP status, SQLSTATE, provider body
 *
 * The default view MUST NOT show exception text, SQLSTATE or capability
 * slugs; they live behind the disclosure. Use `forException()` at call
 * sites that previously piped `$e->getMessage()` straight to the UI.
 */
class NxError
{
    /**
     * Build a presentation array for an exception.
     *
     * @param  string  $title  Translated, plain-language "what failed".
     * @param  string|null  $body  Translated supporting sentence / next step.
     * @param  Throwable|null  $e  The underlying exception (message hidden by default).
     * @param  string|null  $context  Optional operator hint, e.g. the page or action.
     * @return array{title: string, body: string|null, technical: string|null}
     */
    public static function forException(string $title, ?string $body = null, ?Throwable $e = null, ?string $context = null): array
    {
        $technical = null;

        if ($e !== null) {
            $parts = array_filter([
                $context,
                class_basename($e).': '.$e->getMessage(),
            ]);

            $technical = implode(' — ', $parts);
        }

        return [
            'title' => $title,
            'body' => $body,
            'technical' => $technical,
        ];
    }
}
