<?php

namespace App\Services\ControlPlane\Connectors\Support;

use App\Services\ControlPlane\Ai\AiNetworkGuard;

/**
 * Phase 27K.4 — SSRF guard for connector outbound HTTP.
 *
 * Custom connector URLs must be HTTPS and must not target loopback,
 * private ranges or cloud metadata endpoints — UNLESS the operator has
 * explicitly allowed local sources (self-hosted providers) via
 * config('connectors.allow_private_networks') AND the connector manifest
 * declares the network.local_source permission. Local allowance never
 * covers cloud metadata endpoints (169.254.169.254, metadata.*.internal).
 *
 * Check order: scheme → hostname blocklist → IP literal checks → best-effort
 * DNS resolution check. Failing DNS resolution degrades to name-level checks
 * only (documented limitation, no silent permit beyond that).
 */
class ConnectorNetworkGuard
{
    public const METADATA_HOSTS = [
        '169.254.169.254', '169.254.169.25', '100.100.100.200', // AWS/Azure/Alibaba metadata
        'metadata.google.internal', 'metadata.goog', 'metadata.oraclecloud.com',
    ];

    /**
     * Abort when the URL is not safe for connector outbound traffic.
     *
     * @param  bool  $connectorMayUseLocalSource  the connector declared network.local_source
     */
    public static function assertSafeUrl(string $url, bool $connectorMayUseLocalSource = false): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 0);

        abort_if($scheme !== 'https' && $scheme !== 'http', 422, 'Connector URL must be HTTP(S).');

        $localAllowed = $connectorMayUseLocalSource
            && (bool) config('connectors.allow_private_networks', false);

        if ($host === '' || in_array($host, self::METADATA_HOSTS, true)) {
            abort(422, 'Connector URL refused (SSRF guard).');
        }
        if (self::isLoopbackOrInternalName($host) && ! $localAllowed) {
            abort(422, 'Connector URL refused (SSRF guard) — enable CONNECTOR_ALLOW_PRIVATE_NETWORKS for self-hosted sources.');
        }
        if ($scheme === 'http' && ! $localAllowed) {
            abort(422, 'Connector URL must use HTTPS (plain HTTP is reserved for explicitly allowed local sources).');
        }
        if ($port !== 0 && in_array($port, [22, 23, 25, 135, 139, 445, 3389], true)) {
            abort(422, 'Connector URL targets a blocked service port.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            self::assertSafeIp($host, $localAllowed);

            return;
        }

        // Best-effort DNS pinning check (name-level checks already passed).
        $ip = @gethostbyname($host);
        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) && $ip !== $host) {
            self::assertSafeIp($ip, $localAllowed);
        }
    }

    /**
     * Phase 28B.3 — host/port guard for NON-HTTP connector transports
     * (e.g. the MongoDB wire protocol). Same policy as assertSafeUrl:
     * metadata endpoints are never allowed; loopback/private targets require
     * the operator's local-source allowance AND the connector's declared
     * network.local_source permission.
     */
    public static function assertSafeHost(string $host, int $port, bool $connectorMayUseLocalSource = false): void
    {
        $host = strtolower(trim($host));
        $localAllowed = $connectorMayUseLocalSource
            && (bool) config('connectors.allow_private_networks', false);

        abort_if($host === '' || in_array($host, self::METADATA_HOSTS, true), 422, 'Connector host refused (SSRF guard).');
        if (self::isLoopbackOrInternalName($host) && ! $localAllowed) {
            abort(422, 'Connector host refused (SSRF guard) — enable CONNECTOR_ALLOW_PRIVATE_NETWORKS for self-hosted sources.');
        }
        if (in_array($port, [22, 23, 25, 135, 139, 445, 3389], true)) {
            abort(422, 'Connector host targets a blocked service port.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            self::assertSafeIp($host, $localAllowed);

            return;
        }
        $ip = @gethostbyname($host);
        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) && $ip !== $host) {
            self::assertSafeIp($ip, $localAllowed);
        }
    }

    protected static function isLoopbackOrInternalName(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            return true;
        }
        // Single-label docker service names ("postgres", "app") are private.
        if (! str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return AiNetworkGuard::isInternalHost($host);
    }

    protected static function assertSafeIp(string $ip, bool $localAllowed): void
    {
        if (in_array($ip, self::METADATA_HOSTS, true)) {
            abort(422, 'Connector URL refused (SSRF guard) — metadata endpoints are never allowed.');
        }
        $private = ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($private && ! $localAllowed) {
            abort(422, 'Connector URL refused (SSRF guard) — private network target.');
        }
    }
}
