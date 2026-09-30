<?php

namespace App\Connectors\Postgres;

use App\Connectors\Postgres\Protocol\PdoPgExecutor;
use App\Models\MigrationSource;
use App\Models\Project;
use App\Services\ControlPlane\Connectors\ConnectorCapability;
use App\Services\ControlPlane\Connectors\ConnectorCredentialField;
use App\Services\ControlPlane\Connectors\ConnectorCredentials;
use App\Services\ControlPlane\Connectors\ConnectorDefinition;
use App\Services\ControlPlane\Connectors\ConnectorHealth;
use App\Services\ControlPlane\Connectors\ConnectorManifest;
use App\Services\ControlPlane\Connectors\ConnectorTestResult;
use App\Services\ControlPlane\Connectors\Contracts\AnalyzableSourceConnector;
use App\Services\ControlPlane\Connectors\Contracts\ExtractableSourceConnector;
use App\Services\ControlPlane\Connectors\Contracts\SourceConnector;
use App\Services\ControlPlane\Connectors\Contracts\ValidatableSourceConnector;
use App\Services\ControlPlane\Migration\Contracts\SourceAdapter;
use App\Services\ControlPlane\Migration\SchemaFingerprint;

/**
 * Phase 30 — the generic PostgreSQL connector, built ONLY on the Phase 27
 * Connector SDK (30A). Read-only source connector for ANY standard
 * PostgreSQL database (not only Supabase): pg_catalog-native inspection
 * (30B) with exact type preservation (30C), read-only session enforcement
 * (30A), batched keyset extraction with deterministic resume and honest
 * NEEDS_REVIEW handling of unknown extension types (30C). The core platform
 * learns nothing PostgreSQL-specific beyond what it already knew.
 */
class PostgresConnector implements SourceConnector, AnalyzableSourceConnector, ExtractableSourceConnector, ValidatableSourceConnector
{
    public const KEY = 'postgres';

    private ?ConnectorManifest $manifest = null;
    private ?ConnectorDefinition $definition = null;

    public function manifest(): ConnectorManifest
    {
        return $this->manifest ??= ConnectorManifest::parseFile(__DIR__.DIRECTORY_SEPARATOR.'connector.json');
    }

    public function definition(): ConnectorDefinition
    {
        if ($this->definition !== null) {
            return $this->definition;
        }
        $credentials = [
            new ConnectorCredentialField(
                key: 'password',
                label: 'Password',
                type: 'password',
                secret: true,
                required: false,
                scope: ConnectorCredentialField::SCOPE_SOURCE,
                help: 'Stored encrypted (vault). Use a least-privilege read-only role.',
                capability: ConnectorCapability::DATABASE_METADATA,
            ),
        ];
        $configuration = [
            new ConnectorCredentialField(
                key: 'host',
                label: 'Host',
                type: 'host',
                required: true,
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
            ),
            new ConnectorCredentialField(
                key: 'port',
                label: 'Port',
                type: 'port',
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                default: '5432',
            ),
            new ConnectorCredentialField(
                key: 'database',
                label: 'Database',
                type: 'text',
                required: true,
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
            ),
            new ConnectorCredentialField(
                key: 'username',
                label: 'Username',
                type: 'text',
                required: true,
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
            ),
            new ConnectorCredentialField(
                key: 'ssl_mode',
                label: 'SSL mode',
                type: 'select',
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                options: ['prefer' => 'prefer (default)', 'require' => 'require', 'verify-ca' => 'verify-ca', 'verify-full' => 'verify-full', 'disable' => 'disable'],
                default: 'prefer',
            ),
            new ConnectorCredentialField(
                key: 'schemas',
                label: 'Schemas to import (comma-separated, empty = all)',
                type: 'text',
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                help: 'System schemas are always excluded (30B).',
            ),
            new ConnectorCredentialField(
                key: 'batch_size',
                label: 'Extraction batch size',
                type: 'port',
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                default: '500',
            ),
        ];

        return $this->definition = ConnectorDefinition::fromManifest($this->manifest(), $credentials, $configuration);
    }

    public function credentialSchema(): array
    {
        return array_merge($this->definition()->credentials, $this->definition()->configuration);
    }

    public function capabilities(): array
    {
        return $this->manifest()->capabilities();
    }

    /** 30A.2 — only implemented capabilities are declared; status is honest. */
    public function capabilityStatus(string $capability, array $resolvableFieldKeys = []): string
    {
        if (! in_array($capability, $this->capabilities(), true)) {
            // AUTH_METADATA / STORAGE_METADATA: plain PostgreSQL has no
            // provider auth/storage domain to inventory (30C scope).
            return ConnectorCapability::NOT_SUPPORTED;
        }
        if (in_array($capability, [ConnectorCapability::READ_ONLY_ENFORCEMENT, ConnectorCapability::SOURCE_FINGERPRINT, ConnectorCapability::RESUME], true)) {
            return ConnectorCapability::SUPPORTED;
        }

        return in_array('host', $resolvableFieldKeys, true)
            ? ConnectorCapability::SUPPORTED
            : ConnectorCapability::SUPPORTED_WITH_CONFIGURATION;
    }

    public function analysisRequires(): array
    {
        return ['host', 'database'];
    }

    public function extractionRequires(): array
    {
        return ['host', 'database'];
    }

    public function supportsResume(): bool
    {
        // 30D — keyset pagination on primary keys; PK-less tables report
        // resume='none' per-table in the inventory (honest granularity).
        return true;
    }

    public function providedValidators(): array
    {
        return ['connector_counts', 'postgres_sequence_state', 'postgres_needs_review_types'];
    }

    // ── Connection (30A) ─────────────────────────────────────────────────

    public function testConnection(ConnectorCredentials $credentials): ConnectorTestResult
    {
        $host = (string) ($credentials->get('host') ?? '');
        $database = (string) ($credentials->get('database') ?? '');
        $username = (string) ($credentials->get('username') ?? '');
        if ($host === '' || $database === '' || $username === '') {
            return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, 'Provide host, database and username.');
        }
        try {
            $executor = PdoPgExecutor::forSource(
                [
                    'host' => $host,
                    'port' => (int) ($credentials->get('port', 5432)),
                    'database' => $database,
                    'ssl_mode' => (string) ($credentials->get('ssl_mode', 'prefer')),
                ],
                $username,
                (string) ($credentials->get('password') ?? ''),
                (bool) config('connectors.allow_private_networks', false)
            );
            $server = (new PostgresCatalog($executor))->serverInfo();

            return ConnectorTestResult::pass(
                'PostgreSQL reachable — read-only session enforced (server encoding '.$server['server_encoding'].')',
                ['server_version_num' => $server['version_num'], 'read_only' => $server['transaction_read_only']]
            );
        } catch (\InvalidArgumentException $e) {
            return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, $e->getMessage());
        } catch (\RuntimeException $e) {
            // Read-only pin failure — a HARD stop, never softened (30A).
            return ConnectorTestResult::make(ConnectorTestResult::PROVIDER_ERROR, mb_substr($e->getMessage(), 0, 200));
        } catch (\PDOException $e) {
            // 30A — passwords NEVER appear in error surfaces.
            $message = mb_substr($e->getMessage(), 0, 200);
            $kind = str_contains($message, 'authentication') || str_contains($message, 'password')
                ? ConnectorTestResult::INVALID_CREDENTIAL
                : ConnectorTestResult::NETWORK_ERROR;

            return ConnectorTestResult::make($kind, $message);
        }
    }

    public function health(MigrationSource $source): ConnectorHealth
    {
        if ($source->status === 'disabled') {
            return ConnectorHealth::make(ConnectorHealth::DISABLED, 'source status: disabled');
        }
        if ($source->status === 'error') {
            return ConnectorHealth::make(ConnectorHealth::ERROR, (string) $source->last_error);
        }
        $hasTarget = ! empty($source->connection['database']);

        return ConnectorHealth::make(
            $source->status === 'ready' ? ConnectorHealth::CONNECTED : ($hasTarget ? ConnectorHealth::PARTIAL : ConnectorHealth::DISCONNECTED),
            'database: '.($source->connection['database'] ?? 'not selected'),
        );
    }

    // ── Source lifecycle ─────────────────────────────────────────────────

    public function discoverProjects(ConnectorCredentials $credentials): array
    {
        // A database IS the selectable source — no project discovery surface.
        return [];
    }

    public function createSourceProfile(Project $project, array $selection, array $configuration, ?int $environmentId = null): MigrationSource
    {
        return MigrationSource::create([
            'project_id' => $project->id,
            'environment_id' => $environmentId,
            'type' => self::KEY,
            'display_name' => $configuration['display_name'] ?? ('PostgreSQL: '.($configuration['database'] ?? 'database')),
            'source_ref' => $configuration['database'] ?? null,
            'connection' => array_filter([
                'host' => $configuration['host'] ?? null,
                'port' => $configuration['port'] ?? null,
                'database' => $configuration['database'] ?? null,
                'username' => $configuration['username'] ?? null,
                'ssl_mode' => $configuration['ssl_mode'] ?? null,
                'schemas' => $configuration['schemas'] ?? null,
                'batch_size' => $configuration['batch_size'] ?? null,
                'transport' => $configuration['transport'] ?? null,
            ]),
            'secret_refs' => [],
            'read_only' => true,
            'status' => 'pending',
            'created_by' => auth()->id(),
        ]);
    }

    public function sourceAdapter(MigrationSource $source): SourceAdapter
    {
        return new PostgresSourceAdapter($source);
    }

    public function analyze(MigrationSource $source): array
    {
        return $this->sourceAdapter($source)->inventory();
    }

    public function extract(MigrationSource $source, string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
    {
        return $this->sourceAdapter($source)->streamRows($schema, $table, $columns, $callback, $batchSize);
    }

    /** 30D — connector-provided validation artifacts. */
    public function validateSource(MigrationSource $source): array
    {
        $adapter = $this->sourceAdapter($source);
        $analysis = $adapter->inventory();
        $rowCounts = [];
        foreach ($analysis['tables'] as $table) {
            $rowCounts[$table['name']] = $adapter->countRows((string) $table['schema'], (string) $table['name']);
        }
        $postgres = $analysis['postgres'];
        $fingerprint = $adapter->fingerprint();

        return [
            'kind' => 'connector_validation',
            'connector_key' => self::KEY,
            'database' => $postgres['database'],
            'server_version_num' => $postgres['server']['version_num'] ?? null,
            'schemas' => count($analysis['schemas']),
            'tables' => count($analysis['tables']),
            'row_counts' => $rowCounts,
            'sequences' => count($postgres['sequences']),
            'sequence_states' => collect($postgres['sequences'])
                ->mapWithKeys(fn ($s) => [$s['sequence_name'] => $s['last_value']])
                ->all(),
            'needs_review_types' => $postgres['needs_review_types'],
            'large_objects' => $postgres['large_objects'],
            'read_only_enforced' => $postgres['read_only_enforced'],
            'source_fingerprint' => $fingerprint,
        ];
    }

    public function fingerprint(MigrationSource $source): string
    {
        return $this->sourceAdapter($source)->fingerprint();
    }
}
