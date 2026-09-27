<?php

namespace App\Services\ControlPlane\Ai;

use App\Models\AiProviderConfig;

/**
 * SSRF guard for custom AI base URLs (openai_compatible endpoints).
 * Only http(s), no internal/metadata ranges.
 */
class AiNetworkGuard
{
    public static function assertSafeBaseUrl(AiProviderConfig $config): void
    {
        $base = $config->base_url;
        if ($base === null || $base === '') {
            return; // driver default (trusted vendor host)
        }
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
        abort_if(! in_array($scheme, ['https'], true), 422, 'Custom AI base URL must be HTTPS.');
        abort_if($host === '' || self::isInternalHost($host), 422, 'Custom AI base URL refused (SSRF guard).');
    }

    public static function isInternalHost(string $host): bool
    {
        if (in_array($host, ['localhost', 'metadata.google.internal', 'metadata.goog'], true)) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return false;
    }
}
