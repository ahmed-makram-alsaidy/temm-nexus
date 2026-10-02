<?php

// ── Platform identity & release metadata (Phase 26B/26K) ─────────────
// Generic branding with safe defaults; operators override via environment
// (see .env.example → PLATFORM). The canonical version lives in the root
// VERSION file so scripts, containers and UI all report the same value.

$version = '0.0.0-dev';
// Container: VERSION is bind-mounted at the app root. Host/dev: the canonical
// release version lives in the REPOSITORY ROOT (three levels up from
// apps/owner-console/config).
//
// 0.4.0 fix — order matters. The repo-root file is authoritative and is checked
// FIRST. Previously `base_path('VERSION')` won, and apps/owner-console/VERSION
// is a stale copy (it read 0.3.0-rc.1 while the release was 0.3.0), so both the
// UI footer and `platform:doctor` displayed a version that did not match the
// release. See docs/product/UX_AUDIT_0_4.md finding P3.
foreach ([dirname(__DIR__, 3).'/VERSION', base_path('VERSION')] as $versionFile) {
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
