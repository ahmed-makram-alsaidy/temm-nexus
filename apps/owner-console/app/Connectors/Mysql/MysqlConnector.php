<?php

namespace App\Connectors\Mysql;

use App\Connectors\Mysql\Protocol\PdoMysqlExecutor;
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
use App\Services\ControlPlane\Connectors\Contracts\CdcProbeProvider;
use App\Services\ControlPlane\Connectors\Contracts\ValidatableSourceConnector;
use App\Services\ControlPlane\Migration\Contracts\SourceAdapter;
use App\Services\ControlPlane\Migration\Cdc\CdcProbeResult;
use App\Services\ControlPlane\Migration\SchemaFingerprint;

/**
 * Phase 31 — the MySQL/MariaDB connector, built ONLY on the Phase 27
 * Connector SDK (31A). Read-only source connector migrating MySQL 8+ /
 * MariaDB into PostgreSQL-backed projects: explicit unsigned-safe type
 * mapping (31B/31C), AUTO_INCREMENT state preservation (31D), charset
 * analysis with honest transcoding review (31E), batched keyset extraction
 * (31D) and scheduled-event inventory (31A). The core platform learns
 * nothing MySQL-specific.
 */
class MysqlConnector implements SourceConnector, AnalyzableSourceConnector, ExtractableSourceConnector, ValidatableSourceConnector, CdcProbeProvider
{
    public const KEY = 'mysql';

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
                help: 'Stored encrypted (vault). Use a least-privilege read-only account.',
                capability: ConnectorCapability::DATABASE_METADATA,
            ),
        ];
        $configuration = [
            new ConnectorCredentialField(key: 'host', label: 'Host', type: 'host', required: true, scope: ConnectorCredentialField::SCOPE_CONFIGURATION),
            new ConnectorCredentialField(key: 'port', label: 'Port', type: 'port', scope: ConnectorCredentialField::SCOPE_CONFIGURATION, default: '3306'),
            new ConnectorCredentialField(
                key: 'database',
                label: 'Database',
                type: 'text',
                required: true,
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                help: 'Single database per import flow.',
            ),
            new ConnectorCredentialField(key: 'username', label: 'Username', type: 'text', required: true, scope: ConnectorCredentialField::SCOPE_CONFIGURATION),
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

    /** Only implemented capabilities are declared; status is honest. */
    public function capabilityStatus(string $capability, array $resolvableFieldKeys = []): string
    {
        if (! in_array($capability, $this->capabilities(), true)) {
            return ConnectorCapability::NOT_SUPPORTED;
        }
        if (in_array($capability, [ConnectorCapability::READ_ONLY_ENFORCEMENT, ConnectorCapability::SOURCE_FINGERPRINT, ConnectorCapability::RESUME], true)) {
            return ConnectorCapability::SUPPORTED;
        }
        if ($capability === ConnectorCapability::SCHEDULE_METADATA) {
            return ConnectorCapability::PARTIAL; // events inventoried; job EXECUTION is not migrated
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
        return true; // keyset on PK; PK-less tables report resume='none'
    }

    public function providedValidators(): array
    {
        return ['connector_counts', 'mysql_auto_increment_state', 'mysql_unsigned_boundaries', 'mysql_transcoding_review'];
    }

    // ── Connection ───────────────────────────────────────────────────────

    public function testConnection(ConnectorCredentials $credentials): ConnectorTestResult
    {
        $host = (string) ($credentials->get('host') ?? '');
        $database = (string) ($credentials->get('database') ?? '');
        $username = (string) ($credentials->get('username') ?? '');
        if ($host === '' || $database === '' || $username === '') {
            return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, 'Provide host, database and username.');
        }
        try {
            $executor = PdoMysqlExecutor::forSource(
                ['host' => $host, 'port' => (int) ($credentials->get('port', 3306)), 'database' => $database],
                $username,
                (string) ($credentials->get('password') ?? ''),
                (bool) config('connectors.allow_private_networks', false)
            );
            $server = (new MysqlCatalog($executor))->serverInfo();

            return ConnectorTestResult::pass(
                ucfirst($server['flavor']).' reachable — read-only session enforced (server '.$server['version'].')',
                ['flavor' => $server['flavor'], 'version' => $server['version']]
            );
        } catch (\InvalidArgumentException $e) {
            return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, $credentials->redactFrom($e->getMessage()));
        } catch (\RuntimeException $e) {
            return ConnectorTestResult::make(ConnectorTestResult::PROVIDER_ERROR, $credentials->redactFrom(mb_substr($e->getMessage(), 0, 200)));
        } catch (\PDOException $e) {
            // 35.5 — no credential value (host/database/user included) may
            // reach an error surface; provider exceptions echo parameters.
            $message = $credentials->redactFrom(mb_substr($e->getMessage(), 0, 200));
            $kind = str_contains($message, 'access denied') || str_contains($message, 'password')
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
        return []; // the database IS the source; no project discovery surface
    }

    public function createSourceProfile(Project $project, array $selection, array $configuration, ?int $environmentId = null): MigrationSource
    {
        return MigrationSource::create([
            'project_id' => $project->id,
            'environment_id' => $environmentId,
            'type' => self::KEY,
            'display_name' => $configuration['display_name'] ?? ('MySQL: '.($configuration['database'] ?? 'database')),
            'source_ref' => $configuration['database'] ?? null,
            'connection' => array_filter([
                'host' => $configuration['host'] ?? null,
                'port' => $configuration['port'] ?? null,
                'database' => $configuration['database'] ?? null,
                'username' => $configuration['username'] ?? null,
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
        return new MysqlSourceAdapter($source);
    }

    public function analyze(MigrationSource $source): array
    {
        return $this->sourceAdapter($source)->inventory();
    }

    public function extract(MigrationSource $source, string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
    {
        return $this->sourceAdapter($source)->streamRows($schema, $table, $columns, $callback, $batchSize);
    }

    /** Connector-provided validation artifacts (31D/31C/31E). */
    public function validateSource(MigrationSource $source): array
    {
        $adapter = $this->sourceAdapter($source);
        $analysis = $adapter->inventory();
        $rowCounts = [];
        foreach ($analysis['tables'] as $table) {
            $rowCounts[$table['name']] = $adapter->countRows((string) $table['schema'], (string) $table['name']);
        }
        $mysql = $analysis['mysql'];

        return [
            'kind' => 'connector_validation',
            'connector_key' => self::KEY,
            'flavor' => $mysql['server']['flavor'],
            'server_version' => $mysql['server']['version'],
            'database' => $mysql['database'],
            'tables' => count($analysis['tables']),
            'row_counts' => $rowCounts,
            'auto_increment_states' => $mysql['auto_increment'],
            'unsigned_widenings' => $mysql['unsigned_widenings'],
            'transcoding_review' => $mysql['transcoding_review'],
            'read_only_enforced' => $mysql['read_only_enforced'],
            'source_fingerprint' => $adapter->fingerprint(),
        ];
    }

    public function fingerprint(MigrationSource $source): string
    {
        return $this->sourceAdapter($source)->fingerprint();
    }

    /**
     * Phase 32D — read-only probe of binlog readiness. The platform NEVER
     * mutates server configuration (32D); instructions are operator-owned.
     */
    public function cdcProbe(MigrationSource $source): array
    {
        $adapter = $this->sourceAdapter($source);
        $executor = $adapter->catalog()->executor();
        $rows = $executor->rows("SHOW VARIABLES WHERE Variable_name IN ('log_bin', 'binlog_format', 'binlog_row_image', 'gtid_mode')");
        $byName = [];
        foreach ($rows as $row) {
            $byName[strtolower((string) $row['variable_name'])] = strtolower((string) $row['value']);
        }
        $logBin = $byName['log_bin'] ?? 'off';
        $format = $byName['binlog_format'] ?? 'unknown';
        $ready = in_array($logBin, ['on', '1', 'true'], true) && $format === 'row';

        return CdcProbeResult::make(
            'binlog',
            'binlog_gtid',
            $ready ? 'SUPPORTED' : 'SUPPORTED_WITH_CONFIGURATION',
            [
                'log_bin' => $logBin,
                'binlog_format' => $format,
                'binlog_row_image' => $byName['binlog_row_image'] ?? 'unknown',
                'gtid_mode' => $byName['gtid_mode'] ?? 'unknown',
            ],
            $ready ? [] : [
                'Enable binlog with binlog_format=ROW and binlog_row_image=FULL — a SERVER CONFIGURATION CHANGE the platform never performs itself (32D).',
                'Grant the migration account REPLICATION SLAVE + REPLICATION CLIENT (SELECT alone cannot read the binlog).',
                'Prefer GTID mode ON for crash-safe positioning. Rehearse on a disposable source first.',
            ],
        );
    }
}
