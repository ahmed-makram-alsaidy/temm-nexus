<?php

// ── Platform identity & release metadata (Phase 26B/26K) ─────────────
// Generic branding with safe defaults; operators override via environment
// (see .env.example → PLATFORM). The canonical version lives in the root
// VERSION file so scripts, containers and UI all report the same value.

$version = '0.0.0-dev';
// Container: VERSION is bind-mounted at the app root; host/dev: repo root is
// three levels up from this config directory (apps/owner-console/config).
foreach ([base_path('VERSION'), dirname(__DIR__, 3).'/VERSION'] as $versionFile) {
    if (is_readable($versionFile)) {
        $candidate = trim((string) file_get_contents($versionFile));
        if ($candidate !== '' && preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $candidate)) {
            $version = $candidate;
            break;
        }
    }
}

return [

    'name' => env('PLATFORM_NAME', 'TEMM Nexus'),
    'brand' => env('BRAND_NAME', env('PLATFORM_NAME', 'TEMM Nexus')),
    'support_url' => env('SUPPORT_URL'),
    'security_contact' => env('SECURITY_CONTACT'),

    // Canonical SemVer (root VERSION file). Never claim 1.x maturity until
    // the release process (docs/open-source/RELEASE_PROCESS.md) says so.
    'version' => $version,

    // Minimum platform version an upgrade path may start from (26H.5).
    'min_upgrade_from' => '0.1.0',

];
