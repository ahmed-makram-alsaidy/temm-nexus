<?php

namespace App\Services\ControlPlane\Connectors\Contracts;

use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\ConnectorCredentials;
use App\Services\ControlPlane\Connectors\ConnectorDefinition;
use App\Services\ControlPlane\Connectors\ConnectorHealth;
use App\Services\ControlPlane\Connectors\ConnectorManifest;
use App\Services\ControlPlane\Connectors\ConnectorTestResult;

/**
 * Phase 27B — the base connector contract.
 *
 * A connector is a self-describing, versioned integration unit. The core
 * platform resolves connectors through the registry and drives them through
 * these operations; it never learns provider-specific API details.
 *
 * Implementations MUST:
 * - derive their identity from a validated ConnectorManifest (27J);
 * - keep secret values server-side only (27E.2) — credentials arrive as an
 *   already-resolved ConnectorCredentials set and must never be echoed,
 *   logged or persisted by the connector;
 * - classify every failure honestly (27B/27C) — never fake support.
 */
interface Connector
{
    /** The validated package manifest backing this connector (27J.2). */
    public function manifest(): ConnectorManifest;

    /** Stable public identity (27A.2) — manifest + declarative schemas. */
    public function definition(): ConnectorDefinition;

    /**
     * Full declarative credential schema (27E.1): secret + configuration
     * fields, with the capability each field unlocks (27C.2).
     *
     * @return list<ConnectorCredentialField>
     */
    public function credentialSchema(): array;

    /**
     * Static capability list (27C): which vocabulary keys this connector can
     * support at all. Per-instance dynamic status is resolved separately.
     *
     * @return list<string>
     */
    public function capabilities(): array;

    /**
     * Dynamic capability status (27C.2) for one capability key given the
     * credential fields currently resolvable for an instance. One of the
     * ConnectorCapability status constants — support is never faked.
     */
    public function capabilityStatus(string $capability, array $resolvableFieldKeys = []): string;

    /**
     * Test a connection with the given (operation-scoped) credentials.
     * Must classify failures (27B) and never leak secret values in detail.
     */
    public function testConnection(ConnectorCredentials $credentials): ConnectorTestResult;

    /**
     * Health of a configured source instance (27Q.3): CONNECTED, PARTIAL,
     * DISCONNECTED, ERROR or DISABLED.
     */
    public function health(MigrationSource $source): ConnectorHealth;
}
