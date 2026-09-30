<?php

namespace App\Connectors\Firebase;

use App\Connectors\Firebase\Protocol\FirebaseRestClient;
use App\Connectors\Firebase\Protocol\FirebaseTransportException;
use App\Connectors\Firebase\Protocol\FixtureFirebaseTransport;
use App\Connectors\Firebase\Protocol\ServiceAccount;
use App\Models\MigrationSource;
use App\Models\Project;
use App\Services\ControlPlane\Connectors\ConnectorCapability;
use App\Services\ControlPlane\Connectors\ConnectorCredentialField;
use App\Services\ControlPlane\Connectors\ConnectorCredentials;
use App\Services\ControlPlane\Connectors\ConnectorDefinition;
use App\Services\ControlPlane\Connectors\ConnectorHealth;
use App\Services\ControlPlane\Connectors\ConnectorManifest;
use App\Services\ControlPlane\Connectors\Support\ConnectorNetworkGuard;
use App\Services\ControlPlane\Connectors\ConnectorTestResult;
use App\Services\ControlPlane\Connectors\Contracts\AnalyzableSourceConnector;
use App\Services\ControlPlane\Connectors\Contracts\CdcProbeProvider;
use App\Services\ControlPlane\Connectors\Contracts\ClientScannerProvider;
use App\Services\ControlPlane\Connectors\Contracts\ExtractableSourceConnector;
use App\Services\ControlPlane\Connectors\Contracts\SourceConnector;
use App\Services\ControlPlane\Connectors\Contracts\ValidatableSourceConnector;
use App\Services\ControlPlane\Migration\Contracts\SourceAdapter;
use App\Services\ControlPlane\Migration\Cdc\CdcProbeResult;

/**
 * Phase 29 — the Firebase connector, built ONLY on the Phase 27 Connector
 * SDK (29A). Read-only source connector for Firebase projects: Firestore
 * analysis with inferred schema (29C), relationship candidates with
 * confidence classes (29D), batched document extraction with deterministic
 * ordering (29E), honest Firebase Auth inventory with NO password material
 * (29F), Storage inventory with copy strategies (29G), Cloud Functions
 * inventory (29H) and client repository scanning (29I). The core platform
 * learns nothing Firebase-specific.
 */
class FirebaseConnector implements SourceConnector, AnalyzableSourceConnector, ExtractableSourceConnector, ValidatableSourceConnector, ClientScannerProvider, CdcProbeProvider
{
    public const KEY = 'firebase';

    private ?ConnectorManifest $manifest = null;
    private ?ConnectorDefinition $definition = null;
    private ?FirebaseClientScanner $scanner = null;

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
            // 29B — the service account JSON is a SECRET (vault, encrypted at
            // rest); never echoed after save, never sent to AI, never stored
            // in migration artifacts.
            new ConnectorCredentialField(
                key: 'service_account',
                label: 'Service account JSON (vault-stored)',
                type: 'password',
                secret: true,
                required: false,
                scope: ConnectorCredentialField::SCOPE_SOURCE,
                help: 'Paste the full service account JSON. Stored encrypted; used only to mint short-lived read tokens (29B).',
                capability: ConnectorCapability::DATABASE_METADATA,
            ),
        ];
        $configuration = [
            new ConnectorCredentialField(
                key: 'project_id',
                label: 'Firebase project ID',
                type: 'text',
                required: true,
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                help: 'The project to import (e.g. my-project).',
            ),
            new ConnectorCredentialField(
                key: 'database_id',
                label: 'Firestore database id',
                type: 'text',
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                default: '(default)',
                help: 'Usually (default).',
            ),
            new ConnectorCredentialField(
                key: 'storage_bucket',
                label: 'Storage bucket (optional)',
                type: 'text',
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                help: 'Defaults to {project_id}.appspot.com.',
            ),
            new ConnectorCredentialField(
                key: 'use_emulator',
                label: 'Use local Firebase emulator',
                type: 'boolean',
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                help: 'Point the connector at the local emulator suite instead of production Firebase (29J).',
            ),
            new ConnectorCredentialField(
                key: 'emulator_host',
                label: 'Emulator host:port',
                type: 'host',
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                default: '127.0.0.1:8080',
                help: 'Only used when the emulator option is enabled.',
            ),
            new ConnectorCredentialField(
                key: 'sample_size',
                label: 'Schema inference sample size',
                type: 'port',
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                default: (string) FirestoreAnalyzer::DEFAULT_SAMPLE_SIZE,
                help: 'Documents sampled per collection for schema inference (29C). Conservative default.',
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

    /** 29A.2 — only implemented capabilities are declared; status is honest. */
    public function capabilityStatus(string $capability, array $resolvableFieldKeys = []): string
    {
        if (! in_array($capability, $this->capabilities(), true)) {
            // STORAGE_CONTENT (object bytes) is validated at rehearsal time,
            // not inventoried as a capability; INCREMENTAL_EXPORT (29E/32E)
            // is NOT implemented and never reported as supported.
            return ConnectorCapability::NOT_SUPPORTED;
        }
        if (in_array($capability, [ConnectorCapability::READ_ONLY_ENFORCEMENT, ConnectorCapability::SOURCE_FINGERPRINT, ConnectorCapability::RESUME], true)) {
            return ConnectorCapability::SUPPORTED;
        }

        return in_array('project_id', $resolvableFieldKeys, true)
            ? ConnectorCapability::SUPPORTED
            : ConnectorCapability::SUPPORTED_WITH_CONFIGURATION;
    }

    public function analysisRequires(): array
    {
        return ['project_id'];
    }

    public function extractionRequires(): array
    {
        return ['project_id'];
    }

    public function supportsResume(): bool
    {
        // 29E — __name__-ordered range streaming makes re-runs deterministic;
        // the engine replays failed items from the start of the item.
        return true;
    }

    public function providedValidators(): array
    {
        return ['connector_counts', 'firebase_document_coverage', 'firebase_storage_checksum'];
    }

    // ── Connection (29B) ─────────────────────────────────────────────────

    public function testConnection(ConnectorCredentials $credentials): ConnectorTestResult
    {
        $projectId = (string) ($credentials->get('project_id') ?? '');
        $emulator = (bool) $credentials->get('use_emulator', false);
        $serviceAccountJson = (string) ($credentials->get('service_account') ?? '');
        if ($projectId === '') {
            return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, 'Provide a Firebase project ID.');
        }
        if (! $emulator && $serviceAccountJson === '') {
            return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, 'Provide a service account JSON (or enable emulator mode).');
        }
        if ($serviceAccountJson !== '') {
            try {
                ServiceAccount::parse($serviceAccountJson);
            } catch (\InvalidArgumentException $e) {
                return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, $e->getMessage());
            }
        }
        try {
            $client = $this->clientFor($credentials, $projectId);
            // Read-only probe: root collection listing (list-only call).
            $collections = $client->listRootCollections();

            return ConnectorTestResult::pass('Firestore reachable — '.count($collections).' top-level collection(s)', [
                'collections' => count($collections),
            ]);
        } catch (FirebaseTransportException $e) {
            // 29B — credentials NEVER appear in error surfaces.
            if ($e->isAuthFailure()) {
                return ConnectorTestResult::make(ConnectorTestResult::INVALID_CREDENTIAL, 'Firebase rejected the credential (HTTP '.$e->getStatusCode().').');
            }
            if ($e->isNotFound()) {
                return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, 'Firebase project or database not found (HTTP 404).');
            }

            return ConnectorTestResult::make(ConnectorTestResult::NETWORK_ERROR, mb_substr($e->getMessage(), 0, 200));
        } catch (\Throwable $e) {
            return ConnectorTestResult::make(ConnectorTestResult::NETWORK_ERROR, mb_substr($e->getMessage(), 0, 200));
        }
    }

    /** Build the REST client for a connection test (fixture transport supported). */
    protected function clientFor(ConnectorCredentials $credentials, string $projectId): FirebaseRestClient
    {
        $emulator = (bool) $credentials->get('use_emulator', false);
        $emulatorHost = (string) ($credentials->get('emulator_host') ?? '127.0.0.1:8080');
        if ($emulator) {
            [$host, $port] = $this->splitHostPort($emulatorHost, 8080);
            ConnectorNetworkGuard::assertSafeHost($host, $port, (bool) config('connectors.allow_private_networks', false));
        }
        $account = $serviceAccountJson !== '' ? ServiceAccount::parse($serviceAccountJson) : null;

        return new FirebaseRestClient(
            projectId: $projectId,
            databaseId: (string) ($credentials->get('database_id') ?? '(default)'),
            storageBucket: (string) ($credentials->get('storage_bucket') ?? ''),
            emulator: $emulator,
            emulatorHost: $emulatorHost,
            account: $emulator ? null : $account,
            transport: null,
        );
    }

    /** @return array{0: string, 1: int} */
    protected function splitHostPort(string $hostPort, int $defaultPort): array
    {
        $colon = strrpos($hostPort, ':');

        return $colon === false
            ? [$hostPort, $defaultPort]
            : [substr($hostPort, 0, $colon), (int) substr($hostPort, $colon + 1)];
    }

    public function health(MigrationSource $source): ConnectorHealth
    {
        if ($source->status === 'disabled') {
            return ConnectorHealth::make(ConnectorHealth::DISABLED, 'source status: disabled');
        }
        if ($source->status === 'error') {
            return ConnectorHealth::make(ConnectorHealth::ERROR, (string) $source->last_error);
        }
        $hasProject = ! empty($source->connection['project_id']);

        return ConnectorHealth::make(
            $source->status === 'ready' ? ConnectorHealth::CONNECTED : ($hasProject ? ConnectorHealth::PARTIAL : ConnectorHealth::DISCONNECTED),
            'project: '.($source->connection['project_id'] ?? 'not selected'),
        );
    }

    // ── Source lifecycle ─────────────────────────────────────────────────

    public function discoverProjects(ConnectorCredentials $credentials): array
    {
        // 29A — Firebase has no project listing surface for service accounts;
        // the project is declared by the operator. Honest empty discovery.
        return [];
    }

    public function createSourceProfile(Project $project, array $selection, array $configuration, ?int $environmentId = null): MigrationSource
    {
        return MigrationSource::create([
            'project_id' => $project->id,
            'environment_id' => $environmentId,
            'type' => self::KEY,
            'display_name' => $configuration['display_name'] ?? ('Firebase: '.($configuration['project_id'] ?? 'project')),
            'source_ref' => $configuration['project_id'] ?? null,
            'connection' => array_filter([
                'project_id' => $configuration['project_id'] ?? null,
                'database_id' => $configuration['database_id'] ?? null,
                'storage_bucket' => $configuration['storage_bucket'] ?? null,
                'use_emulator' => $configuration['use_emulator'] ?? null,
                'emulator_host' => $configuration['emulator_host'] ?? null,
                'sample_size' => $configuration['sample_size'] ?? null,
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
        return new FirebaseSourceAdapter($source);
    }

    public function analyze(MigrationSource $source): array
    {
        return $this->sourceAdapter($source)->inventory();
    }

    public function extract(MigrationSource $source, string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
    {
        return $this->sourceAdapter($source)->streamRows($schema, $table, $columns, $callback, $batchSize);
    }

    /** 29Q — connector-provided validation artifacts. */
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
        $firebase = $analysis['firebase'];

        return [
            'kind' => 'connector_validation',
            'connector_key' => self::KEY,
            'project' => $firebase['project_id'],
            'database_id' => $firebase['database_id'],
            'collections' => count($analysis['tables']),
            'row_counts' => $rowCounts,
            'inferred_schema_note' => 'Firestore schema is inferred evidence, not authoritative (29C).',
            'relationship_candidates' => count($firebase['relationships']),
            'auth_users' => count($firebase['auth']['users']),
            'auth_password_users' => $firebase['auth']['password_users'],
            'auth_password_compatibility' => $firebase['auth']['migration']['password_compatibility'],
            'storage_objects' => $firebase['storage']['object_count'],
            'storage_bytes' => $firebase['storage']['total_bytes'],
            'functions' => $firebase['functions']['count'],
            'functions_available' => $firebase['functions']['available'],
            'source_fingerprint' => $adapter->fingerprint(),
        ];
    }

    public function fingerprint(MigrationSource $source): string
    {
        return $this->sourceAdapter($source)->fingerprint();
    }

    /**
     * Phase 32E — honest refusal: no robust generic CDC semantics could be
     * proven for Firebase, so no partial capture is offered. The final
     * delta step is a fresh snapshot export before cutover.
     */
    public function cdcProbe(MigrationSource $source): array
    {
        return CdcProbeResult::make(
            null,
            null,
            'NOT_SUPPORTED',
            [],
            [
                'Firebase delta capture is DEFERRED (32E): no robust generic CDC semantics could be proven.',
                'Re-run the Firebase export (snapshot) as the final delta step before cutover instead.',
            ],
        );
    }

    // ── 29I — client scanner contribution (delegated) ────────────────────

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

    protected function scannerProvider(): FirebaseClientScanner
    {
        return $this->scanner ??= new FirebaseClientScanner;
    }
}
