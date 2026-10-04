<?php

namespace App\Services\Agent;

use App\Services\Agent\Contract\AgentRuntimeException;

/**
 * SSRF posture for EXTERNAL agent-runtime endpoints. Mirrors the AI provider
 * guard (App\Services\ControlPlane\Ai\AiNetworkGuard): HTTPS-only, private
 * and metadata ranges refused. `AGENT_ALLOW_LOOPBACK_ENDPOINTS=true` exists
 * purely for local development against the shipped mock server and must
 * never be enabled in production.
 *
 * Managed-mode endpoints are trusted by construction: they come from the
 * operator's own compose configuration (internal Docker DNS), not from
 * user input.
 */
class AgentNetworkGuard
{
    /**
     * @throws AgentRuntimeException
     */
    public static function assertSafeEndpoint(string $baseUrl, bool $managed): void
    {
        if ($managed) {
            return; // operator-controlled internal service endpoint
        }

        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));

        if (self::allowLoopback() && in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
            return; // explicit local-development allowance (mock runtime)
        }

        if (! in_array($scheme, ['https'], true)) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::INVALID_RUNTIME_RESPONSE,
                'External agent runtime endpoint must use HTTPS.'
            );
        }

        if ($host === '' || self::isInternalHost($host)) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::INVALID_RUNTIME_RESPONSE,
                'External agent runtime endpoint refused (SSRF guard).'
            );
        }
    }

    public static function allowLoopback(): bool
    {
        return (bool) config('agent.allow_loopback_endpoints', false);
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
