<?php

namespace App\Connectors\Postgres;

use App\Connectors\Postgres\Protocol\PdoPgExecutor;
use App\Connectors\Postgres\Replication\PgCdcCapture;
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
use App\Services\ControlPlane\Connectors\Contracts\CdcCaptureProvider;
use App\Services\ControlPlane\Connectors\Contracts\CdcProbeProvider;
use App\Services\ControlPlane\Connectors\Contracts\ValidatableSourceConnector;
use App\Services\ControlPlane\Migration\Contracts\SourceAdapter;
use App\Services\ControlPlane\Migration\Cdc\CdcProbeResult;
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
class PostgresConnector implements SourceConnector, AnalyzableSourceConnector, ExtractableSourceConnector, ValidatableSourceConnector, CdcProbeProvider, CdcCaptureProvider
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
                help: 'System schemas are always excluded.',
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
        if ($capability === ConnectorCapability::CHANGE_CAPTURE || $capability === ConnectorCapability::CONSISTENT_SNAPSHOT) {
            // Real capability (35.6), but never assumed: wal_level=logical,
            // a replication role and slot capacity are SOURCE-side states —
            // the per-instance probe reports them honestly (32B).
            return ConnectorCapability::SUPPORTED_WITH_CONFIGURATION;
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
            return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, $credentials->redactFrom($e->getMessage()));
        } catch (\PDOException $e) {
            // 0.6.0 Phase E (§E5) — PDOException extends RuntimeException, so
            // this catch MUST precede the RuntimeException catch or every
            // credential refusal degrades to PROVIDER_ERROR. Deterministic
            // classification (auth vs network) depends on this order.
            // 30A — passwords NEVER appear in error surfaces; 35.5 — no
            // credential value (host/database/user included) does either.
            $message = $credentials->redactFrom(mb_substr($e->getMessage(), 0, 200));
            $kind = str_contains($message, 'authentication') || str_contains($message, 'password')
                ? ConnectorTestResult::INVALID_CREDENTIAL
                : ConnectorTestResult::NETWORK_ERROR;

            return ConnectorTestResult::make($kind, $message);
        } catch (\RuntimeException $e) {
            // Read-only pin failure and the SSRF guard's refusal (both
            // RuntimeException-shaped) — a HARD stop, never softened (30A).
            // The wizard classifies the guard message into the private-
            // network recovery path.
            return ConnectorTestResult::make(ConnectorTestResult::PROVIDER_ERROR, $credentials->redactFrom(mb_substr($e->getMessage(), 0, 200)));
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
                'fixture_server' => $configuration['fixture_server'] ?? null,
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

    /**
     * Phase 32B — read-only probe of logical-replication readiness. The
     * platform NEVER changes server configuration; the operator follows the
     * returned instructions on a disposable source first.
     */
    public function cdcProbe(MigrationSource $source): array
    {
        $adapter = $this->sourceAdapter($source);
        $executor = $adapter->catalog()->executor();
        $rows = $executor->rows("SELECT name, setting FROM pg_settings WHERE name IN ('wal_level', 'max_replication_slots', 'max_wal_senders')");
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row['setting'];
        }
        $walLevel = strtolower((string) ($byName['wal_level'] ?? 'unknown'));
        $maxSlots = (int) ($byName['max_replication_slots'] ?? 0);
        $maxSenders = (int) ($byName['max_wal_senders'] ?? 0);
        $ready = $walLevel === 'logical' && $maxSlots >= 1 && $maxSenders >= 1;

        $observed = ['wal_level' => $walLevel, 'max_replication_slots' => $maxSlots, 'max_wal_senders' => $maxSenders];

        return CdcProbeResult::make(
            'logical_replication',
            'lsn',
            $ready ? 'SUPPORTED' : 'SUPPORTED_WITH_CONFIGURATION',
            $observed,
            $ready ? [
                'Use a REPLICATION-capable role (REPLICATION attribute or pg_write_all_data membership) for the logical slot.',
                'The migration creates a project-scoped logical slot + publication; both are dropped when the run completes — never leave slots abandoned.',
            ] : [
                "Set wal_level=logical (observed: {$walLevel}) and max_replication_slots>=1, max_wal_senders>=1, then restart PostgreSQL — a SERVER CONFIGURATION CHANGE the platform never performs itself (32B).",
                'Create a dedicated logical replication slot for the migration; drop it after cutover to avoid WAL retention growth.',
                'Use a replication-capable role. Rehearse on a disposable source first — this probe only READS settings.',
            ],
        );
    }

    /**
     * Phase 35.6 — REAL log-based capture (WAL → logical decoding →
     * pgoutput). Requires the source to be replication-ready (see cdcProbe).
     *
     * $options: slot, publication (project+run scoped names), tables
     * (['schema.table', ...] for the publication scope), allow_setup.
     */
    public function cdcCapture(MigrationSource $source, array $options = []): PgCdcCapture
    {
        $options['allow_setup'] ??= (bool) config('cdc.capture.allow_source_setup', false);

        return new PgCdcCapture($source, $options);
    }
}
