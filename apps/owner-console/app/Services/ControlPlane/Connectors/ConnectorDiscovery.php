<?php

namespace App\Services\ControlPlane\Connectors;

use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\Connectors\Contracts\Connector;

/**
 * Phase 27J.1 — first-party connector package discovery.
 *
 * Connector packages are directories containing a `connector.json` manifest
 * plus an entrypoint class. Discovery validates every manifest strictly
 * (27J.3) before the connector can register; malformed, incompatible or
 * oversized packages are REJECTED safely and recorded for observability —
 * never partially loaded (27T).
 *
 * Phase 27J.4 — third-party/remote installation is intentionally NOT
 * implemented. Only locally-shipped packages at configured paths are
 * discovered; future third-party installs will be a privileged admin action.
 */
class ConnectorDiscovery
{
    /** @return array{registered: list<string>, rejected: array<string, string>, duplicates: list<string>} */
    public static function discover(?array $paths = null, ?ConnectorRegistry $registry = null): array
    {
        $registry ??= app(ConnectorRegistry::class);
        $paths ??= config('connectors.paths', [app_path('Connectors')]);
        $report = ['registered' => [], 'rejected' => [], 'duplicates' => []];

        foreach ((array) $paths as $path) {
            if (! is_dir($path)) {
                continue;
            }
            foreach (glob(rtrim($path, '/\\').'/*/connector.json') ?: [] as $manifestPath) {
                self::registerPackage(dirname($manifestPath), $registry, $report);
            }
        }

        return $report;
    }

    /**
     * Discover + register a single package directory (also used by
     * connector:make). Returns the connector on success, null when rejected.
     */
    public static function registerPackage(string $packageDir, ?ConnectorRegistry $registry = null, ?array &$report = null): ?Connector
    {
        $registry ??= app(ConnectorRegistry::class);
        if ($report === null) {
            $report = ['registered' => [], 'rejected' => [], 'duplicates' => []];
        }
        $packageKey = basename($packageDir);
        $manifestPath = rtrim($packageDir, '/\\').DIRECTORY_SEPARATOR.'connector.json';

        try {
            $manifest = ConnectorManifest::parseFile($manifestPath);
            $entrypoint = $manifest->entrypoint();

            /** @var Connector $connector */
            $connector = app($entrypoint);
            if (! $connector instanceof Connector) {
                throw ConnectorManifestInvalid::with(["entrypoint '{$entrypoint}' does not implement the connector contract"]);
            }
            if ($connector->manifest()->key() !== $manifest->key()) {
                throw ConnectorManifestInvalid::with([
                    "entrypoint manifest key mismatch: package declares '{$manifest->key()}' but connector reports '{$connector->manifest()->key()}'",
                ]);
            }

            // Phase 27K.1 — trust gating: only first_party packages register
            // enabled; other trust levels register disabled and uninvokable.
            $enableByTrust = $manifest->trust() === 'first_party'
                && in_array('first_party', (array) config('connectors.enabled_trust_levels', ['first_party']), true);
            $registry->register($connector, $enableByTrust);
            $report['registered'][] = $manifest->key();

            return $connector;
        } catch (ConnectorKeyConflict $e) {
            $report['duplicates'][] = $packageKey;
            $registry->reject($packageKey, $e->getMessage());

            return null;
        } catch (\Throwable $e) {
            $reason = $e->getMessage();
            $report['rejected'][$packageKey] = $reason;
            $registry->reject($packageKey, $reason);
            try {
                AdminAudit::record('CONNECTOR_PACKAGE_REJECTED', null, 'connector_package', null, [
                    'package' => $packageKey,
                    'reason' => mb_substr($reason, 0, 200),
                ]);
            } catch (\Throwable) {
                // Discovery can run before migrations exist (early boot);
                // the rejection is still recorded on the registry itself.
            }

            return null;
        }
    }
}
