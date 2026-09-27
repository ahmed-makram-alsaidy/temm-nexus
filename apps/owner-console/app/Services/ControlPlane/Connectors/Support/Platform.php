<?php

namespace App\Services\ControlPlane\Connectors\Support;

/**
 * Phase 27R — platform version accessor for connector compatibility gates.
 * Reads the canonical version already resolved by config/platform.php.
 */
final class Platform
{
    /** Canonical platform SemVer string, e.g. "0.1.0-rc.2" or "0.2.0-dev". */
    public static function version(): string
    {
        $version = (string) config('platform.version', '0.0.0-dev');

        return $version !== '' ? $version : '0.0.0-dev';
    }

    /**
     * Numeric major.minor.patch core of a version (prerelease stripped):
     * "0.1.0-rc.2" → "0.1.0".
     */
    public static function core(?string $version = null): string
    {
        $version ??= self::version();
        if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) === 1) {
            return $m[1];
        }

        return '0.0.0';
    }
}
