<?php

namespace App\Services\ControlPlane\Ai;

use App\Models\AiProviderConfig;

/**
 * SSRF guard for custom AI base URLs (openai_compatible endpoints).
 * Only http(s), no internal/metadata ranges.
 *
 * 0.4.0-rc.7 — AI_ALLOW_LOOPBACK_ENDPOINTS=true (local development only)
 * permits http://127.0.0.1 / http://[::1] / http://localhost endpoints, so
 * the AgentRouter acceptance flow can be validated offline against the
 * shipped mock provider (scripts/mock-agentrouter.php). The flag defaults
 * to false and must never be set in production; when it is unset the guard
 * behaves exactly as before.
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

        if (self::allowLoopback() && in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
            return; // explicit local-development allowance (mock endpoints)
        }

        abort_if(! in_array($scheme, ['https'], true), 422, 'Custom AI base URL must be HTTPS.');
        abort_if($host === '' || self::isInternalHost($host), 422, 'Custom AI base URL refused (SSRF guard).');
    }

    public static function allowLoopback(): bool
    {
        return (bool) config('nexus-ai.allow_loopback_endpoints', false);
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
