<?php

namespace App\Services\ControlPlane\Migration;

use App\Models\MigrationAnalysis;

/**
 * 0.6.0 Phase E (§E8) — deterministic analysis outcome classification.
 *
 * A benign, optional inspection failure (the live specimen was a
 * permission denial on pg_largeobject) must NEVER flip an analysis or its
 * source to ERROR. This class owns the boundary, so every surface — wizard,
 * Migration journey, source health — agrees because they call it.
 *
 * Rules (deterministic, ordered):
 *  1. Recorded OPTIONAL-probe failures are WARNINGS. The analysis completes
 *     and the source stays usable; what could not be inspected is named.
 *  2. An exception that prevents the core inventory (connect/auth/schema
 *     access) is BLOCKING. The analysis fails and the source reports why.
 *
 * Raw SQLSTATE / provider text travels inside the payload's `technical`
 * fields only — never rendered by default.
 */
class AnalysisOutcomeClassifier
{
    public const BLOCKING = 'blocking';
    public const WARNING = 'warning';

    /**
     * Optional inspection probes. A failure here is recorded as a warning
     * with a stable check identifier; nothing else is affected.
     */
    public const OPTIONAL_CHECKS = [
        'postgres.large_objects' => 'large_objects',
        'postgres.extensions' => 'extensions',
    ];

    /**
     * Classify a recorded optional-probe failure into a warning payload.
     *
     * @return array{severity: string, check: string, sqlstate: ?string, message: string}
     */
    public static function warning(string $check, \Throwable $e): array
    {
        return [
            'severity' => self::WARNING,
            'check' => $check,
            'sqlstate' => self::sqlstate($e),
            'message' => mb_substr($e->getMessage(), 0, 300),
        ];
    }

    /**
     * Classify an exception that escaped the core inventory.
     *
     * @return array{severity: string, kind: string, sqlstate: ?string, message: string}
     */
    public static function blocking(\Throwable $e): array
    {
        $sqlstate = self::sqlstate($e);

        return [
            'severity' => self::BLOCKING,
            // Deterministic families, in order: the credentials were refused,
            // the server could not be reached, the schema is not usable.
            'kind' => match (true) {
                $sqlstate === '28P01' || str_contains(strtolower($e->getMessage()), 'authentication') => 'auth',
                $sqlstate === '42501' || str_contains(strtolower($e->getMessage()), 'permission denied') => 'schema_access',
                default => 'unreachable',
            },
            'sqlstate' => $sqlstate,
            'message' => mb_substr($e->getMessage(), 0, 300),
        ];
    }

    /**
     * SQLSTATE extraction: PDO-style getCode() when present, else the
     * `SQLSTATE[xxxxx]` prefix of the provider message. Deterministic either
     * way, so the Technical details always carry the same code.
     */
    protected static function sqlstate(\Throwable $e): ?string
    {
        if (preg_match('/SQLSTATE\[(?<state>[0-9A-Z]{5})\]/', $e->getMessage(), $m)) {
            return $m['state'];
        }

        $code = (string) $e->getCode();

        return preg_match('/^[0-9A-Z]{5}$/', $code) ? $code : null;
    }

    /**
     * §E8 — present recorded warnings in product language. Every surface
     * (wizard, Migration journey) renders through this one mapping, so a
     * check identifier always reads the same. SQLSTATE and raw provider
     * text are NOT part of this payload — they belong to Technical details.
     *
     * @param  list<array{check: string, domain?: ?string}>  $warnings
     * @return list<array{check: string, title: string, detail: string}>
     */
    public static function present(array $warnings): array
    {
        $out = [];
        foreach ($warnings as $warning) {
            $check = (string) ($warning['check'] ?? '');
            if ($check === '') {
                continue;
            }

            $isDomain = $check === 'domain_not_present';
            $domain = __('migration.counts_'.($warning['domain'] ?? ''));
            $out[] = [
                'check' => $check,
                'title' => $isDomain
                    ? __('migration.warning_domain_not_present', ['domain' => $domain])
                    : __("migration.warning_{$check}"),
                'detail' => $isDomain
                    ? __('migration.warning_domain_not_present_detail', ['domain' => $domain])
                    : __('migration.warning_optional_detail'),
            ];
        }

        return $out;
    }

    /** Raw diagnostics for a Technical details disclosure (§E8/§E23). */
    public static function technicalFor(MigrationAnalysis $analysis): string
    {
        $parts = ['run '.$analysis->run_id];
        if ($analysis->connector_key) {
            $parts[] = 'connector '.$analysis->connector_key.($analysis->connector_version ? ' v'.$analysis->connector_version : '');
        }
        if ($analysis->source_fingerprint) {
            $parts[] = 'fingerprint '.substr((string) $analysis->source_fingerprint, 0, 16).'…';
        }
        foreach ((array) ($analysis->warnings ?? []) as $warning) {
            $parts[] = trim(sprintf('check %s SQLSTATE %s — %s',
                $warning['check'] ?? '?', $warning['sqlstate'] ?? '—', $warning['message'] ?? ''));
        }
        foreach ((array) ($analysis->errors ?? []) as $key => $value) {
            $parts[] = trim("$key: $value");
        }

        return implode(' · ', $parts);
    }
}
