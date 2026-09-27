<?php

namespace App\Services\ControlPlane\Connectors;

use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\Contracts\SourceConnector;
use App\Services\ControlPlane\Connectors\Support\ScopedSecretResolver;
use Illuminate\Support\Str;

/**
 * Phase 27C.2 — dynamic, connector-driven capability probing.
 *
 * Two views, both generic over any source connector:
 *
 * - matrix(): the declarative capability matrix (27C.1 statuses) resolved
 *   from the connector's credential schema and what is currently configured
 *   for the instance — no source contact.
 * - probe(): a live read-only probe against the source producing the same
 *   domain view the Phase 25 Supabase wizard consumed (behavior preserved),
 *   derived from the connector's normalized inventory.
 */
class ConnectorCapabilityProbe
{
    /**
     * Dynamic capability matrix (27C.1/27C.2). Rows:
     * ['capability', 'label', 'status', 'detail'].
     */
    public static function matrix(MigrationSource $source): array
    {
        $rows = [];
        try {
            $connector = ConnectorRegistry::instance()->connectorForSource($source);
        } catch (\Throwable $e) {
            return [['capability' => null, 'label' => 'Connector', 'status' => ConnectorCapability::NOT_SUPPORTED, 'detail' => Str::limit($e->getMessage(), 160)]];
        }

        $resolvable = ScopedSecretResolver::resolvableFieldKeys($source, $connector->definition());

        foreach ($connector->capabilities() as $capability) {
            $status = $connector->capabilityStatus($capability, $resolvable);
            $rows[] = [
                'capability' => $capability,
                'label' => ConnectorCapability::label($capability),
                'status' => $status,
                'detail' => self::statusDetail($status),
            ];
        }

        return $rows;
    }

    protected static function statusDetail(string $status): string
    {
        return match ($status) {
            ConnectorCapability::SUPPORTED => 'available with current configuration',
            ConnectorCapability::SUPPORTED_WITH_CONFIGURATION => 'supply the required credentials to enable',
            ConnectorCapability::PARTIAL => 'partially available',
            ConnectorCapability::NOT_APPLICABLE => 'not applicable to this source',
            default => 'not supported by this connector',
        };
    }

    /**
     * Live read-only capability probe (shape compatible with the Phase 25
     * Supabase probe: overall PROBED|NEEDS_CREDENTIAL|FAILED + domains).
     * Domains are derived from the connector's NORMALIZED inventory, so any
     * source connector gets the same treatment.
     */
    public static function probe(MigrationSource $source): array
    {
        try {
            $connector = ConnectorRegistry::instance()->connectorForSource($source);
        } catch (\Throwable $e) {
            return ['overall' => 'FAILED', 'error' => Str::limit($e->getMessage(), 200), 'domains' => []];
        }
        if (! $connector instanceof SourceConnector) {
            return ['overall' => 'FAILED', 'error' => 'connector does not support source operations', 'domains' => []];
        }

        $definition = $connector->definition();
        $resolvable = ScopedSecretResolver::resolvableFieldKeys($source, $definition);
        $analysisRequires = method_exists($connector, 'analysisRequires') ? $connector->analysisRequires() : [];
        foreach ($analysisRequires as $required) {
            if (! in_array($required, $resolvable, true)) {
                return ['overall' => 'NEEDS_CREDENTIAL', 'domains' => []];
            }
        }

        try {
            $adapter = $connector->sourceAdapter($source);
            $adapter->connect();
            $inventory = $adapter->inventory();
            $adapter->close();
        } catch (\Throwable $e) {
            return ['overall' => 'FAILED', 'error' => Str::limit($e->getMessage(), 200), 'domains' => []];
        }

        $domains = self::domainsFromInventory($inventory);

        // Persist the probe result on the source for the UI (Phase 25 behavior).
        $source->update(['capabilities' => $domains, 'status' => 'ready']);

        return ['overall' => 'PROBED', 'domains' => $domains];
    }

    /** Generic normalized-inventory → capability-domain mapping. */
    public static function domainsFromInventory(array $inventory): array
    {
        $domains = [];
        $domains['database'] = count($inventory['tables'] ?? []) > 0 ? 'PASS' : 'PARTIAL';
        $domains['rls'] = count($inventory['policies'] ?? []) > 0 || collect($inventory['tables'] ?? [])->contains(fn ($t) => $t['rls_enabled'] ?? false) ? 'PASS' : 'PARTIAL';
        $auth = $inventory['auth'] ?? [];
        $domains['auth'] = ($auth['present'] ?? false) ? ($auth['users_count'] > 0 ? 'PASS' : 'PARTIAL') : 'PARTIAL';
        $storage = $inventory['storage'] ?? [];
        $domains['storage'] = ($storage['present'] ?? false) ? 'PASS' : 'PARTIAL';
        $domains['functions'] = count($inventory['functions'] ?? []) > 0 ? 'PASS' : 'PARTIAL';
        $domains['views'] = count($inventory['views'] ?? []) > 0 || count($inventory['matviews'] ?? []) > 0 ? 'PASS' : 'PARTIAL';
        $domains['extensions'] = count($inventory['extensions'] ?? []) > 0 ? 'PASS' : 'PARTIAL';

        return $domains;
    }

    /**
     * Connector instance health (27Q.3): connection/account status + probe
     * state collapsed into the stable health vocabulary.
     */
    public static function health(MigrationSource $source): string
    {
        try {
            $connector = ConnectorRegistry::instance()->connectorForSource($source);
            if ($connector->health($source)->status === ConnectorHealth::DISABLED) {
                return ConnectorHealth::DISABLED;
            }
        } catch (\Throwable) {
            return ConnectorHealth::ERROR;
        }
        if ($source->status === 'disabled') {
            return ConnectorHealth::DISABLED;
        }
        if ($source->status === 'error') {
            return ConnectorHealth::ERROR;
        }
        $account = $source->externalAccountConnection;
        $accountOk = $account === null || $account->status === 'connected';
        if ($source->status === 'ready' && $accountOk) {
            return ConnectorHealth::CONNECTED;
        }

        return ConnectorHealth::PARTIAL;
    }
}
