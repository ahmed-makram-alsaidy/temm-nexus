<?php

namespace App\Connectors\Mongodb;

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
use App\Services\ControlPlane\Connectors\Contracts\CdcProbeProvider;
use App\Services\ControlPlane\Connectors\Contracts\ClientScannerProvider;
use App\Services\ControlPlane\Connectors\Contracts\ExtractableSourceConnector;
use App\Services\ControlPlane\Connectors\Contracts\SourceConnector;
use App\Services\ControlPlane\Connectors\Contracts\ValidatableSourceConnector;
use App\Services\ControlPlane\Connectors\Support\ConnectorNetworkGuard;
use App\Services\ControlPlane\Migration\Contracts\SourceAdapter;
use App\Services\ControlPlane\Migration\Cdc\CdcProbeResult;
use App\Services\ControlPlane\Migration\SchemaFingerprint;
use App\Connectors\Mongodb\Protocol\BsonCodec;
use App\Connectors\Mongodb\Protocol\MongoWireClient;

/**
 * Phase 28 — the MongoDB connector, built ONLY on the Phase 27 Connector
 * SDK (28A). Read-only source connector for Atlas and self-hosted MongoDB:
 * database/collection discovery (28C/28D), probabilistic schema inference
 * (28E), relationship candidates with confidence (28F), index/validator
 * analysis (28G), batched extraction with _id-ordered resumption (28H),
 * deterministic strategy mapping (28I), GridFS metadata (28N) and honest
 * auth semantics (28O). The core platform learns nothing MongoDB-specific.
 */
class MongodbConnector implements SourceConnector, AnalyzableSourceConnector, ExtractableSourceConnector, ValidatableSourceConnector, ClientScannerProvider, CdcProbeProvider
{
    public const KEY = 'mongodb';

    private ?ConnectorManifest $manifest = null;
    private ?ConnectorDefinition $definition = null;
    private ?MongodbClientScanner $scanner = null;

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
            // 28B.1 — the URI carries credentials and is stored as a SECRET
            // (vault, encrypted at rest); never echoed after save.
            new ConnectorCredentialField(
                key: 'uri',
                label: 'Connection URI (mongodb:// or mongodb+srv://)',
                type: 'password',
                secret: true,
                required: false,
                scope: ConnectorCredentialField::SCOPE_SOURCE,
                help: 'Stored encrypted. Preferred form — supports Atlas SRV, TLS options and replica sets.',
                capability: ConnectorCapability::DATABASE_METADATA,
            ),
            new ConnectorCredentialField(
                key: 'password',
                label: 'Password (split config alternative)',
                type: 'password',
                secret: true,
                required: false,
                scope: ConnectorCredentialField::SCOPE_SOURCE,
                capability: ConnectorCapability::DATABASE_METADATA,
            ),
        ];
        $configuration = [
            new ConnectorCredentialField(
                key: 'host',
                label: 'Host (alternative to URI)',
                type: 'host',
                required: false,
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                capability: ConnectorCapability::DATABASE_METADATA,
            ),
            new ConnectorCredentialField(key: 'port', label: 'Port', type: 'port', scope: ConnectorCredentialField::SCOPE_CONFIGURATION, default: '27017'),
            new ConnectorCredentialField(key: 'username', label: 'Username (split config)', type: 'text', scope: ConnectorCredentialField::SCOPE_CONFIGURATION),
            new ConnectorCredentialField(
                key: 'database',
                label: 'Database to import',
                type: 'text',
                required: true,
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                help: 'Single database per import flow (28C). System databases (admin/config/local) are excluded from discovery.',
            ),
            new ConnectorCredentialField(key: 'auth_source', label: 'Auth source', type: 'text', scope: ConnectorCredentialField::SCOPE_CONFIGURATION, default: 'admin'),
            new ConnectorCredentialField(key: 'tls', label: 'TLS', type: 'boolean', scope: ConnectorCredentialField::SCOPE_CONFIGURATION),
            new ConnectorCredentialField(
                key: 'read_preference',
                label: 'Read preference',
                type: 'select',
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                options: ['primary' => 'primary (default — correctness first)', 'primaryPreferred' => 'primaryPreferred', 'secondaryPreferred' => 'secondaryPreferred (may be stale)'],
                default: 'primary',
            ),
            new ConnectorCredentialField(
                key: 'sample_size',
                label: 'Schema inference sample size',
                type: 'port',
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                default: (string) SchemaInferer::DEFAULT_SAMPLE_SIZE,
                help: 'Documents sampled per collection for schema inference (28E.1). Conservative default.',
            ),
            new ConnectorCredentialField(key: 'server_selection_timeout_ms', label: 'Server selection timeout (ms)', type: 'port', scope: ConnectorCredentialField::SCOPE_CONFIGURATION, default: '10000'),
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

    /** 28A.2 — only implemented capabilities are declared; status is honest. */
    public function capabilityStatus(string $capability, array $resolvableFieldKeys = []): string
    {
        if (! in_array($capability, $this->capabilities(), true)) {
            // AUTH_METADATA / STORAGE_METADATA (content) / POLICY_METADATA /
            // INCREMENTAL_EXPORT (change streams) are NOT implemented and
            // never reported as supported (28A.2, 28P).
            return ConnectorCapability::NOT_SUPPORTED;
        }
        if (in_array($capability, [ConnectorCapability::READ_ONLY_ENFORCEMENT, ConnectorCapability::SOURCE_FINGERPRINT], true)) {
            return ConnectorCapability::SUPPORTED;
        }

        return (in_array('uri', $resolvableFieldKeys, true) || in_array('host', $resolvableFieldKeys, true))
            ? ConnectorCapability::SUPPORTED
            : ConnectorCapability::SUPPORTED_WITH_CONFIGURATION;
    }

    public function analysisRequires(): array
    {
        return ['database'];
    }

    public function extractionRequires(): array
    {
        return ['database'];
    }

    public function supportsResume(): bool
    {
        // 28H.1 — _id-ordered range-scan resumption; BSON comparison order
        // makes this safe for ANY _id type (not just ObjectId).
        return true;
    }

    public function providedValidators(): array
    {
        return ['connector_counts', 'mongodb_id_coverage', 'mongodb_jsonb_equivalence'];
    }

    // ── Connection (28B) ─────────────────────────────────────────────────

    public function testConnection(ConnectorCredentials $credentials): ConnectorTestResult
    {
        $uri = (string) ($credentials->get('uri') ?? '');
        $host = (string) ($credentials->get('host') ?? '');
        if ($uri === '' && $host === '') {
            return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, 'Provide a connection URI or host.');
        }
        try {
            $config = [
                'host' => $host,
                'port' => $credentials->get('port', 27017),
                'tls' => $credentials->get('tls', false),
                'auth_source' => $credentials->get('auth_source'),
            ];
            $effective = $uri !== '' ? $uri : $this->assembleUri($config, $credentials);
            foreach ($this->hostsFor($effective) as $target) {
                ConnectorNetworkGuard::assertSafeHost(
                    $target['host'],
                    $target['port'],
                    connectorMayUseLocalSource: true // permission declared in manifest; config still gates
                );
            }
            $client = MongoWireClient::fromUri(
                $effective,
                (int) ($credentials->get('server_selection_timeout_ms', 10000)),
                (string) ($credentials->get('read_preference', 'primary'))
            );
            $hello = $client->connect();
            $client->close();
            $version = (string) ($hello['version']['v'] ?? 'unknown');

            return ConnectorTestResult::pass('wire protocol handshake OK (server '.$version.')', ['server_version' => $version]);
        } catch (\InvalidArgumentException $e) {
            return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, $credentials->redactFrom($e->getMessage()));
        } catch (\Throwable $e) {
            // 28T — URI (and its credentials) NEVER appear in error surfaces;
            // 35.5 — no credential value (host/user included) does either.
            return ConnectorTestResult::make(
                str_contains($e->getMessage(), 'authentication') ? ConnectorTestResult::INVALID_CREDENTIAL : ConnectorTestResult::NETWORK_ERROR,
                $credentials->redactFrom(mb_substr($e->getMessage(), 0, 200))
            );
        }
    }

    protected function assembleUri(array $config, ConnectorCredentials $credentials): string
    {
        $username = (string) ($credentials->get('username') ?? '');
        $password = (string) ($credentials->get('password') ?? '');
        $auth = $username !== '' ? rawurlencode($username).':'.rawurlencode($password).'@' : '';
        $tls = ! empty($config['tls']) ? '?tls=true' : '';

        return sprintf('mongodb://%s%s:%d/%s%s', $auth, $config['host'], (int) $config['port'], (string) ($credentials->get('database') ?? ''), $tls);
    }

    /** @return list<array{host: string, port: int}> */
    protected function hostsFor(string $uri): array
    {
        $parsed = MongoWireClient::parseUri($uri);
        $hosts = [];
        foreach ($parsed['hosts'] as $hostPort) {
            $colon = strrpos($hostPort, ':');
            $hosts[] = $colon === false
                ? ['host' => $hostPort, 'port' => $parsed['srv'] ? 27017 : 27017]
                : ['host' => substr($hostPort, 0, $colon), 'port' => (int) substr($hostPort, $colon + 1)];
        }

        return $hosts;
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

    // ── Source lifecycle (28C/28D) ───────────────────────────────────────

    public function discoverProjects(ConnectorCredentials $credentials): array
    {
        // 28C — list accessible databases; system DBs are EXCLUDED from
        // import by default and marked, never assumed.
        $uri = (string) ($credentials->get('uri') ?? '');
        $host = (string) ($credentials->get('host') ?? '');
        if ($uri === '' && $host === '') {
            return [];
        }
        $effective = $uri !== '' ? $uri : $this->assembleUri(['host' => $host, 'port' => $credentials->get('port', 27017), 'tls' => $credentials->get('tls', false)], $credentials);
        foreach ($this->hostsFor($effective) as $target) {
            ConnectorNetworkGuard::assertSafeHost($target['host'], $target['port'], connectorMayUseLocalSource: true);
        }
        $client = MongoWireClient::fromUri($effective, (int) ($credentials->get('server_selection_timeout_ms', 10000)));
        $databases = [];
        foreach ($client->listDatabases() as $db) {
            $name = (string) (BsonCodec::untag($db['name'] ?? null) ?? '');
            if ($name === '') {
                continue;
            }
            $databases[] = [
                'ref' => $name,
                'name' => $name,
                'system' => in_array($name, RelationshipInferer::SYSTEM_DATABASES, true),
                'excluded_by_default' => in_array($name, RelationshipInferer::SYSTEM_DATABASES, true),
            ];
        }
        $client->close();

        return $databases;
    }

    public function createSourceProfile(Project $project, array $selection, array $configuration, ?int $environmentId = null): MigrationSource
    {
        return MigrationSource::create([
            'project_id' => $project->id,
            'environment_id' => $environmentId,
            'type' => self::KEY,
            'display_name' => $configuration['display_name'] ?? ('MongoDB: '.($configuration['database'] ?? 'database')),
            'source_ref' => $configuration['database'] ?? null,
            'connection' => array_filter([
                'host' => $configuration['host'] ?? null,
                'port' => $configuration['port'] ?? null,
                'database' => $configuration['database'] ?? null,
                'auth_source' => $configuration['auth_source'] ?? null,
                'tls' => $configuration['tls'] ?? null,
                'read_preference' => $configuration['read_preference'] ?? null,
                'sample_size' => $configuration['sample_size'] ?? null,
                'server_selection_timeout_ms' => $configuration['server_selection_timeout_ms'] ?? null,
            ]),
            'secret_refs' => [],
            'read_only' => true,
            'status' => 'pending',
            'created_by' => auth()->id(),
        ]);
    }

    public function sourceAdapter(MigrationSource $source): SourceAdapter
    {
        return new MongodbSourceAdapter($source);
    }

    public function analyze(MigrationSource $source): array
    {
        return $this->sourceAdapter($source)->inventory();
    }

    public function extract(MigrationSource $source, string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
    {
        return $this->sourceAdapter($source)->streamRows($schema, $table, $columns, $callback, $batchSize);
    }

    /** 28Q — connector-provided validation artifacts. */
    public function validateSource(MigrationSource $source): array
    {
        $adapter = $this->sourceAdapter($source);
        $analysis = $adapter->inventory();
        $rowCounts = [];
        foreach ($analysis['tables'] as $table) {
            $rowCounts[$table['name']] = $table['row_estimate'] > 0
                ? $table['row_estimate']
                : $adapter->countRows((string) $table['schema'], (string) $table['name']);
        }
        $fingerprint = $adapter->fingerprint();

        return [
            'kind' => 'connector_validation',
            'connector_key' => self::KEY,
            'database' => $analysis['mongodb']['database'],
            'collections' => count($analysis['tables']),
            'row_counts' => $rowCounts,
            'gridfs_buckets' => count($analysis['storage']['buckets'] ?? []),
            'schema_variance_fields' => collect($analysis['mongodb']['collections'] ?? [])->sum('schema_variance_fields'),
            'candidate_relationships' => count($analysis['mongodb']['relationships'] ?? []),
            'source_fingerprint' => $fingerprint,
        ];
    }

    public function fingerprint(MigrationSource $source): string
    {
        return $this->sourceAdapter($source)->fingerprint();
    }

    // ── 28R — client scanner contribution (delegated) ────────────────────

    public function scannerLabel(): string
    {
        return $this->scannerProvider()->scannerLabel();
    }

    public function patternsFor(string $language): array
    {
        return $this->scannerProvider()->patternsFor($language);
    }

    public function secretMarkers(): array
    {
        return $this->scannerProvider()->secretMarkers();
    }

    public function configDirNames(): array
    {
        return $this->scannerProvider()->configDirNames();
    }

    public function hardcodedUrlRiskCode(): string
    {
        return $this->scannerProvider()->hardcodedUrlRiskCode();
    }

    protected function scannerProvider(): MongodbClientScanner
    {
        return $this->scanner ??= new MongodbClientScanner;
    }

    /**
     * Phase 32C — change-stream readiness probe (READ-only hello check).
     * Standalone deployments cannot provide change streams; the probe says
     * so honestly instead of faking parity (28/32).
     */
    public function cdcProbe(MigrationSource $source): array
    {
        try {
            $adapter = $this->sourceAdapter($source);
            $adapter->connect();
            $hello = $adapter->client()->connect();
            $setName = (string) (BsonCodec::untag($hello['setName'] ?? null) ?? '');
            $msg = (string) (BsonCodec::untag($hello['msg'] ?? null) ?? '');
            $supported = $setName !== '' || $msg === 'isdbgrid';

            return CdcProbeResult::make(
                'change_streams',
                'resume_token',
                $supported ? 'SUPPORTED' : 'NOT_SUPPORTED',
                ['replica_set' => $setName !== '', 'sharded' => $msg === 'isdbgrid'],
                $supported ? [] : [
                    'Change streams require a replica set or sharded cluster — standalone deployments cannot provide them (28/32 honesty).',
                    'Resume tokens are stored SIGNED and project-scoped (32C) — no operator action needed.',
                ],
            );
        } catch (\Throwable) {
            return CdcProbeResult::make('change_streams', 'resume_token', 'NOT_SUPPORTED', [], [
                'The deployment could not be reached for a topology check; change capture is not reported as available without proof.',
            ]);
        }
    }
}
