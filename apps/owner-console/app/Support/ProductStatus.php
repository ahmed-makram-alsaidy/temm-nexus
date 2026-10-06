<?php

namespace App\Support;

/**
 * 0.6.0 Phase A — the human-facing status dictionary (audit §A5).
 *
 * Raw stored status values (`not_deployed`, `unknown`, `failed`, …) must
 * never be rendered on normal product surfaces. This is the single place
 * that maps a raw value to a translated LABEL and a semantic TONE, so
 * every surface speaks the same language.
 *
 * Stored enums are NOT changed — this is presentation only. Raw values
 * remain available in technical-details and audit metadata.
 *
 * Unknown values fall through to a neutral tone and the raw value itself
 * is NOT shown as-is; callers decide whether to show the dictionary
 * fallback label ("Unknown state") instead.
 */
class ProductStatus
{
    /** raw value => [tone, translation key base] */
    private const MAP = [
        // Health.
        'healthy' => ['success', 'healthy'],
        'unhealthy' => ['danger', 'unhealthy'],
        'degraded' => ['warning', 'degraded'],
        'unknown' => ['neutral', 'unknown'],

        // Project lifecycle.
        'planned' => ['neutral', 'planned'],
        'active' => ['success', 'active'],
        'paused' => ['warning', 'paused'],
        'archived' => ['neutral', 'archived'],

        // Deployment.
        'not_deployed' => ['neutral', 'not_deployed'],
        'deploying' => ['info', 'deploying'],
        'deployed' => ['success', 'deployed'],
        'deploy_failed' => ['danger', 'deploy_failed'],
        'offline' => ['danger', 'offline'],

        // Migration analysis / runs.
        'pending' => ['neutral', 'pending'],
        'running' => ['info', 'running'],
        'completed' => ['success', 'completed'],
        'failed' => ['danger', 'failed'],
        'cancelled' => ['neutral', 'cancelled'],

        // Capability / connection checks.
        'ready' => ['success', 'ready'],
        'error' => ['danger', 'error'],
        'ok' => ['success', 'ok'],
        'blocked' => ['danger', 'blocked'],
        'unavailable' => ['neutral', 'unavailable'],

        // Webhook deliveries (stored enums unchanged).
        'delivered' => ['success', 'delivered'],
        'exhausted' => ['danger', 'exhausted'],

        // Console users (stored enums unchanged).
        'disabled' => ['neutral', 'disabled'],

        // Backups / restore drills (stored enums unchanged).
        'verified' => ['success', 'verified'],
        'drill_running' => ['info', 'drill_running'],
        'drill_passed' => ['success', 'drill_passed'],
        'drill_failed' => ['danger', 'drill_failed'],

        // Log severities (stored enums unchanged).
        'info' => ['info', 'info'],
        'warning' => ['warning', 'warning'],
        'debug' => ['neutral', 'debug'],
        'critical' => ['danger', 'critical'],

        // Setup system checks (stored uppercase; case-normalized below).
        'pass' => ['success', 'passed'],
        'fail' => ['danger', 'failed'],

        // Cutover verdicts (stored uppercase; normalize before lookup).
        'READY' => ['success', 'cutover_ready'],
        'WARNING' => ['warning', 'cutover_warning'],
        'BLOCKED' => ['danger', 'cutover_blocked'],

        // Readiness colors.
        'green' => ['success', 'green'],
        'yellow' => ['warning', 'yellow'],
        'red' => ['danger', 'red'],
        'not_applicable' => ['neutral', 'not_applicable'],
    ];

    /** Human label for a raw status value, translated. */
    public static function label(string $raw): string
    {
        $entry = self::entry($raw);

        if ($entry !== null) {
            return __("status.{$entry[1]}");
        }

        return __('status.unknown');
    }

    /** Semantic tone: success | warning | danger | info | neutral. */
    public static function tone(string $raw): string
    {
        return self::entry($raw)[0] ?? 'neutral';
    }

    /**
     * Case-normalized lookup. Setup checks store PASS/FAIL/INFO uppercase
     * while every other domain stores lowercase — the dictionary handles
     * both without changing a single stored enum.
     */
    private static function entry(string $raw): ?array
    {
        return self::MAP[$raw]
            ?? self::MAP[strtoupper($raw)]
            ?? self::MAP[strtolower($raw)]
            ?? null;
    }

    /** Filament badge color name for a raw status value. */
    public static function color(string $raw): string
    {
        return match (self::tone($raw)) {
            'success' => 'success',
            'warning' => 'warning',
            'danger' => 'danger',
            'info' => 'info',
            default => 'gray',
        };
    }

    /** True when the dictionary knows this raw value. */
    public static function known(string $raw): bool
    {
        return self::entry($raw) !== null;
    }
}
