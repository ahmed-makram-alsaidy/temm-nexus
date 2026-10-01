<?php

namespace App\Connectors\Supabase;

use App\Models\ExternalAccountConnection;
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
use App\Services\ControlPlane\Connectors\Contracts\AccountDiscoveryConnector;
use App\Services\ControlPlane\Connectors\Contracts\ClientScannerProvider;
use App\Services\ControlPlane\Connectors\Contracts\DiscoverableSourceConnector;
use App\Services\ControlPlane\Connectors\Contracts\ExtractableSourceConnector;
use App\Services\ControlPlane\Connectors\Contracts\SourceConnector;
use App\Services\ControlPlane\Connectors\Contracts\ValidatableSourceConnector;
use App\Services\ControlPlane\Migration\Contracts\SourceAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Phase 27H — the Supabase connector behind the Connector SDK.
 *
 * Everything Supabase-specific from Phase 25 lives in this package: account
 * connection (PAT, management API), read-only PostgreSQL adapter, capability
 * probing and client scanner patterns. The core platform resolves this
 * connector through the registry (stable key "supabase") and drives it via
 * the SourceConnector contract — behavior is unchanged from Phase 25
 * (27H.2 no-regression requirement).
 */
class SupabaseConnector implements SourceConnector, AccountDiscoveryConnector, AnalyzableSourceConnector, ExtractableSourceConnector, ValidatableSourceConnector, ClientScannerProvider
{
    public const KEY = 'supabase';

    private ?ConnectorManifest $manifest = null;
    private ?ConnectorDefinition $definition = null;
    private ?SupabaseClientScanner $scanner = null;

    public function manifest(): ConnectorManifest
    {
        return $this->manifest ??= ConnectorManifest::parseFile(__DIR__.DIRECTORY_SEPARATOR.'connector.json');
    }

    public function definition(): ConnectorDefinition
    {
        if ($this->definition !== null) {
            return $this->definition;
        }
        [$credentials, $configuration] = $this->schemas();

        return $this->definition = ConnectorDefinition::fromManifest($this->manifest(), $credentials, $configuration);
    }

    /** @return array{0: list<ConnectorCredentialField>, 1: list<ConnectorCredentialField>} */
    protected function schemas(): array
    {
        // Field keys deliberately mirror the Phase 24/25 MigrationSource
        // storage (connection JSON keys + secret_refs names) so existing
        // sources keep working unchanged through the SDK.
        $credentials = [
            new ConnectorCredentialField(
                key: 'pat',
                label: 'Personal Access Token',
                type: 'password',
                secret: true,
                required: true,
                scope: ConnectorCredentialField::SCOPE_ACCOUNT,
                help: 'Supabase management API token. Encrypted at rest; used for project discovery only.',
                capability: ConnectorCapability::PROJECT_DISCOVERY,
            ),
            new ConnectorCredentialField(
                key: 'password',
                label: 'Database password (read-only)',
                type: 'password',
                secret: true,
                required: true,
                scope: ConnectorCredentialField::SCOPE_SOURCE,
                help: 'Stored in the project vault; session is forced read-only (default_transaction_read_only).',
                capability: ConnectorCapability::DATABASE_METADATA,
            ),
        ];
        $configuration = [
            new ConnectorCredentialField(
                key: 'host',
                label: 'Database host',
                type: 'host',
                required: true,
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                default: '127.0.0.1',
                capability: ConnectorCapability::DATABASE_METADATA,
            ),
            new ConnectorCredentialField(
                key: 'port',
                label: 'Database port',
                type: 'port',
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                default: '5432',
            ),
            new ConnectorCredentialField(
                key: 'database',
                label: 'Database name',
                type: 'text',
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                default: 'postgres',
            ),
            new ConnectorCredentialField(
                key: 'username',
                label: 'Database user',
                type: 'text',
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                default: 'postgres',
            ),
            new ConnectorCredentialField(
                key: 'schema',
                label: 'Main schema',
                type: 'text',
                scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
                default: 'public',
            ),
        ];

        return [$credentials, $configuration];
    }

    public function credentialSchema(): array
    {
        return array_merge(...array_values($this->schemas()));
    }

    public function capabilities(): array
    {
        return $this->manifest()->capabilities();
    }

    /** 27C.2 — dynamic capability status from the resolvable credential fields. */
    public function capabilityStatus(string $capability, array $resolvableFieldKeys = []): string
    {
        if (! in_array($capability, $this->capabilities(), true)) {
            return ConnectorCapability::NOT_SUPPORTED;
        }
        $has = fn (string $field) => in_array($field, $resolvableFieldKeys, true);

        return match ($capability) {
            ConnectorCapability::ACCOUNT_DISCOVERY,
            ConnectorCapability::PROJECT_DISCOVERY => $has('pat')
                ? ConnectorCapability::SUPPORTED
                : ConnectorCapability::SUPPORTED_WITH_CONFIGURATION,
            ConnectorCapability::DATABASE_METADATA,
            ConnectorCapability::DATA_EXTRACTION,
            ConnectorCapability::AUTH_METADATA,
            ConnectorCapability::STORAGE_METADATA,
            ConnectorCapability::FUNCTION_METADATA,
            ConnectorCapability::POLICY_METADATA,
            ConnectorCapability::REALTIME_METADATA,
            ConnectorCapability::SCHEDULE_METADATA => $has('password') && $has('host')
                ? ConnectorCapability::SUPPORTED
                : ConnectorCapability::SUPPORTED_WITH_CONFIGURATION,
            ConnectorCapability::CLIENT_SCAN,
            ConnectorCapability::READ_ONLY_ENFORCEMENT,
            ConnectorCapability::SOURCE_FINGERPRINT => ConnectorCapability::SUPPORTED,
            default => ConnectorCapability::NOT_SUPPORTED,
        };
    }

    public function discoveryRequires(): array
    {
        return ['pat'];
    }

    public function analysisRequires(): array
    {
        return ['password', 'host'];
    }

    public function extractionRequires(): array
    {
        return ['password', 'host'];
    }

    public function supportsResume(): bool
    {
        return false; // offset-batched re-runs only; no persisted resume tokens yet
    }

    /**
     * 27B.1 — connection test. With a PAT: management API classification
     * (same semantics as the Phase 25A account test, without touching the
     * connection record). With DB credentials: a read-only connect/close.
     */
    public function testConnection(ConnectorCredentials $credentials): ConnectorTestResult
    {
        if ($credentials->has('pat')) {
            try {
                // Endpoint base comes from SupabaseAccountService::managementApi()
                // which carries its own production HTTPS guard (26.1B) — the
                // generic 27K.4 SSRF guard applies to custom connector URLs.
                $base = SupabaseAccountService::managementApi();
                $response = Http::timeout(15)->withToken($credentials->get('pat'))
                    ->accept('application/json')->get($base.'/v1/projects');
                $result = match (true) {
                    $response->status() === 200 => ConnectorTestResult::PASS,
                    $response->status() === 401 => ConnectorTestResult::INVALID_CREDENTIAL,
                    $response->status() === 403 => ConnectorTestResult::INSUFFICIENT_SCOPE,
                    $response->status() === 429 => ConnectorTestResult::RATE_LIMITED,
                    $response->status() >= 500 => ConnectorTestResult::PROVIDER_ERROR,
                    default => ConnectorTestResult::UNKNOWN_ERROR,
                };

                return ConnectorTestResult::make($result, $result === ConnectorTestResult::PASS ? 'management API reachable' : 'HTTP '.$response->status());
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                return ConnectorTestResult::make(ConnectorTestResult::NETWORK_ERROR, $credentials->redactFrom(Str::limit($e->getMessage(), 160)));
            } catch (\Throwable $e) {
                return ConnectorTestResult::make(ConnectorTestResult::UNKNOWN_ERROR, $credentials->redactFrom(Str::limit($e->getMessage(), 160)));
            }
        }

        if ($credentials->has('host') && $credentials->has('password')) {
            return $this->testDatabaseConnection($credentials);
        }

        return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, 'No testable credentials supplied (need PAT or database password).');
    }

    protected function testDatabaseConnection(ConnectorCredentials $credentials): ConnectorTestResult
    {
        try {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;options=\'--client_encoding=UTF8\'',
                (string) $credentials->get('host', '127.0.0.1'),
                (int) $credentials->get('port', 5432),
                (string) $credentials->get('database', 'postgres'),
            );
            $pdo = new \PDO($dsn, (string) $credentials->get('username', 'postgres'), (string) $credentials->get('password'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 8,
            ]);
            $pdo->exec('SET default_transaction_read_only = on');
            $readOnly = $pdo->query('SHOW transaction_read_only')->fetchColumn();
            $pdo = null;

            return $readOnly === 'on'
                ? ConnectorTestResult::pass('read-only PostgreSQL session established')
                : ConnectorTestResult::make(ConnectorTestResult::UNKNOWN_ERROR, 'session did not enforce read-only');
        } catch (\PDOException $e) {
            $message = $credentials->redactFrom(Str::limit($e->getMessage(), 160));
            $class = match (true) {
                str_contains($message, 'password authentication failed') || str_contains($message, 'authentication failed') => ConnectorTestResult::INVALID_CREDENTIAL,
                str_contains($message, 'could not find driver') => ConnectorTestResult::INVALID_CONFIGURATION,
                default => ConnectorTestResult::NETWORK_ERROR,
            };

            return ConnectorTestResult::make($class, $message);
        } catch (\Throwable $e) {
            return ConnectorTestResult::make(ConnectorTestResult::UNKNOWN_ERROR, $credentials->redactFrom(Str::limit($e->getMessage(), 160)));
        }
    }

    public function health(MigrationSource $source): ConnectorHealth
    {
        $checks = [];
        $account = $source->externalAccountConnection;
        if ($account !== null) {
            $checks['account'] = $account->status === 'connected' ? 'CONNECTED' : ($account->status === 'error' ? 'ERROR' : 'PARTIAL');
        }
        $checks['database'] = ! empty($source->secret_refs['password']) && ! empty($source->connection['host']) ? 'CONNECTED' : 'DISCONNECTED';

        $status = match ($source->status) {
            'disabled' => ConnectorHealth::DISABLED,
            'error' => ConnectorHealth::ERROR,
            'ready' => in_array('ERROR', $checks, true) ? ConnectorHealth::PARTIAL : ConnectorHealth::CONNECTED,
            'pending' => in_array('CONNECTED', $checks, true) ? ConnectorHealth::PARTIAL : ConnectorHealth::DISCONNECTED,
            default => ConnectorHealth::DISCONNECTED,
        };

        return ConnectorHealth::make($status, 'source status: '.$source->status, $checks);
    }

    // ── Source lifecycle operations ─────────────────────────────────────

    public function discoverProjects(ConnectorCredentials $credentials): array
    {
        // 27E.3 — discovery receives ONLY the PAT, never database secrets.
        $accountCredentials = $credentials->only(['pat']);
        $connection = new ExternalAccountConnection;
        $connection->secret_encrypted = (string) $accountCredentials->get('pat');
        $connection->status = 'connected';

        return SupabaseAccountService::discoverProjects($connection);
    }

    // ── Phase 27G.2 — account import flow operations (contract) ─────────

    public function connectAccount($user, string $displayName, string $secret): ExternalAccountConnection
    {
        return SupabaseAccountService::connect($user, $displayName, $secret);
    }

    public function testAccount(ExternalAccountConnection $connection): array
    {
        return SupabaseAccountService::testConnection($connection);
    }

    public function discoverAccountProjects(ExternalAccountConnection $connection): array
    {
        return SupabaseAccountService::discoverProjects($connection);
    }

    public function createSourceProfile(Project $project, array $selection, array $configuration, ?int $environmentId = null): MigrationSource
    {
        if (isset($selection['account_connection']) && $selection['account_connection'] instanceof ExternalAccountConnection) {
            // Phase 25 account flow (behavior preserved).
            return SupabaseAccountService::selectProject($project, $selection['account_connection'], $selection['discovered'] ?? [], $environmentId);
        }

        // Generic credentials-form path (Migration Center / generic wizard).
        $secretRefs = [];
        if (! empty($configuration['password_secret_name'])) {
            $secretRefs['password'] = (string) $configuration['password_secret_name'];
        }

        $source = MigrationSource::create([
            'project_id' => $project->id,
            'environment_id' => $environmentId,
            'type' => self::KEY,
            'display_name' => $configuration['display_name'] ?? 'Supabase source',
            'source_ref' => $configuration['source_ref'] ?? null,
            'connection' => array_filter([
                'host' => $configuration['host'] ?? null,
                'port' => $configuration['port'] ?? null,
                'database' => $configuration['database'] ?? null,
                'username' => $configuration['username'] ?? null,
                'schema' => $configuration['schema'] ?? null,
            ]),
            'secret_refs' => $secretRefs,
            'read_only' => true,
            'status' => 'pending',
            'created_by' => auth()->id(),
        ]);

        return $source;
    }

    public function sourceAdapter(MigrationSource $source): SourceAdapter
    {
        return new SupabaseSourceAdapter($source);
    }

    public function analyze(MigrationSource $source): array
    {
        $adapter = $this->sourceAdapter($source);
        $adapter->connect();

        try {
            return $adapter->inventory();
        } finally {
            $adapter->close();
        }
    }

    public function extract(MigrationSource $source, string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
    {
        return $this->sourceAdapter($source)->streamRows($schema, $table, $columns, $callback, $batchSize);
    }

    /** 27B.4 — connector-provided validation artifacts (counts + fingerprint). */
    public function validateSource(MigrationSource $source): array
    {
        $inventory = $this->analyze($source);
        $rowCounts = [];
        foreach ($inventory['tables'] ?? [] as $table) {
            $rowCounts[($table['schema'] ?? 'public').'.'.$table['name']] = (int) ($table['row_estimate'] ?? 0);
        }

        return [
            'kind' => 'connector_validation',
            'connector_key' => self::KEY,
            'tables' => count($inventory['tables'] ?? []),
            'row_counts' => $rowCounts,
            'auth_users' => $inventory['auth']['users_count'] ?? 0,
            'storage_buckets' => count($inventory['storage']['buckets'] ?? []),
            'policies' => count($inventory['policies'] ?? []),
            'functions' => count($inventory['functions'] ?? []),
            'source_fingerprint' => $this->fingerprint($source),
        ];
    }

    public function fingerprint(MigrationSource $source): string
    {
        return $this->sourceAdapter($source)->fingerprint();
    }

    public function providedValidators(): array
    {
        // Connector-contributed validator names on top of the generic suite.
        return ['connector_counts'];
    }

    // ── Phase 27B — client scanner contribution (delegated) ─────────────

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

    protected function scannerProvider(): SupabaseClientScanner
    {
        return $this->scanner ??= new SupabaseClientScanner;
    }
}
