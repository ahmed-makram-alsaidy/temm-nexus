<?php

namespace App\Connectors\ExampleJson;

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
use App\Services\ControlPlane\Connectors\Support\ProjectScopedFileReader;
use App\Services\ControlPlane\Migration\Contracts\SourceAdapter;

/**
 * Phase 27N — the Example JSON connector.
 *
 * A non-Supabase demonstration source connector built ONLY on the Connector
 * SDK: local JSON dataset files (users/orders/order_items) analyzed with
 * schema inference, extracted in batches, planned and migrated by the
 * standard Migration Center pipeline (27N.3 — no Supabase code participates).
 * Phase 28's MongoDB connector will follow this exact shape.
 */
class ExampleJsonConnector implements SourceConnector, AnalyzableSourceConnector, ExtractableSourceConnector, ValidatableSourceConnector
{
    public const KEY = 'example-json';

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
        $datasetField = new ConnectorCredentialField(
            key: 'dataset_path',
            label: 'Dataset directory',
            type: 'file',
            secret: false,
            required: true,
            scope: ConnectorCredentialField::SCOPE_CONFIGURATION,
            help: 'Absolute path to a directory of JSON files — one collection per file (e.g. users.json with a JSON array of objects).',
            capability: ConnectorCapability::DATABASE_METADATA,
        );

        return $this->definition = ConnectorDefinition::fromManifest($this->manifest(), [], [$datasetField]);
    }

    public function credentialSchema(): array
    {
        return $this->definition()->configuration;
    }

    public function capabilities(): array
    {
        return $this->manifest()->capabilities();
    }

    public function capabilityStatus(string $capability, array $resolvableFieldKeys = []): string
    {
        if (! in_array($capability, $this->capabilities(), true)) {
            return ConnectorCapability::NOT_SUPPORTED;
        }
        if ($capability === ConnectorCapability::READ_ONLY_ENFORCEMENT || $capability === ConnectorCapability::SOURCE_FINGERPRINT) {
            return ConnectorCapability::SUPPORTED;
        }

        return in_array('dataset_path', $resolvableFieldKeys, true)
            ? ConnectorCapability::SUPPORTED
            : ConnectorCapability::SUPPORTED_WITH_CONFIGURATION;
    }

    public function analysisRequires(): array
    {
        return ['dataset_path'];
    }

    public function extractionRequires(): array
    {
        return ['dataset_path'];
    }

    public function supportsResume(): bool
    {
        return false;
    }

    public function providedValidators(): array
    {
        return ['connector_counts'];
    }

    public function testConnection(ConnectorCredentials $credentials): ConnectorTestResult
    {
        $path = (string) $credentials->get('dataset_path', '');
        if ($path === '') {
            return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, 'dataset_path is required.');
        }
        try {
            $files = ProjectScopedFileReader::datasetFiles($path, 'json', (int) config('connectors.limits.max_dataset_files', 50));
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 200);

            return ConnectorTestResult::make(
                str_contains($message, 'not exist') ? ConnectorTestResult::NOT_FOUND : ConnectorTestResult::INVALID_CONFIGURATION,
                $message
            );
        }
        if ($files === []) {
            return ConnectorTestResult::make(ConnectorTestResult::INVALID_CONFIGURATION, 'No .json dataset files found in the directory.');
        }

        return ConnectorTestResult::pass(count($files).' JSON dataset file(s) found', ['files' => count($files)]);
    }

    public function health(MigrationSource $source): ConnectorHealth
    {
        if ($source->status === 'disabled') {
            return ConnectorHealth::make(ConnectorHealth::DISABLED, 'source status: disabled');
        }
        if ($source->status === 'error') {
            return ConnectorHealth::make(ConnectorHealth::ERROR, (string) $source->last_error);
        }
        $path = (string) ($source->connection['dataset_path'] ?? '');
        if ($path === '' || ! is_dir($path)) {
            return ConnectorHealth::make(ConnectorHealth::DISCONNECTED, 'dataset_path not configured or missing');
        }

        return ConnectorHealth::make(ConnectorHealth::CONNECTED, 'dataset ready: '.$path);
    }

    // ── Source lifecycle operations ─────────────────────────────────────

    public function discoverProjects(ConnectorCredentials $credentials): array
    {
        // Local dataset connector: no account/project discovery — the
        // operator points directly at a dataset directory.
        return [];
    }

    public function createSourceProfile(Project $project, array $selection, array $configuration, ?int $environmentId = null): MigrationSource
    {
        return MigrationSource::create([
            'project_id' => $project->id,
            'environment_id' => $environmentId,
            'type' => self::KEY,
            'display_name' => $configuration['display_name'] ?? 'Example JSON dataset',
            'source_ref' => $configuration['source_ref'] ?? basename((string) ($configuration['dataset_path'] ?? 'dataset')),
            'connection' => array_filter([
                'dataset_path' => $configuration['dataset_path'] ?? null,
                'manifest' => $configuration['manifest'] ?? null,
            ]),
            'secret_refs' => [],
            'read_only' => true,
            'status' => 'pending',
            'created_by' => auth()->id(),
        ]);
    }

    public function sourceAdapter(MigrationSource $source): SourceAdapter
    {
        return new ExampleJsonSourceAdapter($source);
    }

    public function analyze(MigrationSource $source): array
    {
        return $this->sourceAdapter($source)->inventory();
    }

    public function extract(MigrationSource $source, string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
    {
        return $this->sourceAdapter($source)->streamRows($schema, $table, $columns, $callback, $batchSize);
    }

    /** 27B.4 — connector-provided validation artifacts. */
    public function validateSource(MigrationSource $source): array
    {
        $inventory = $this->analyze($source);
        $rowCounts = [];
        $fkIssues = [];
        $adapter = $this->sourceAdapter($source);
        foreach ($inventory['tables'] as $table) {
            $rowCounts[$table['name']] = $adapter->countRows('public', $table['name']);
            foreach ($table['foreign_keys'] as $fk) {
                $referencedIds = [];
                $adapter->streamRows('public', $fk['references_table'], [$fk['references_column']], function ($row) use (&$referencedIds, $fk) {
                    $referencedIds[(string) ($row[$fk['references_column']] ?? '')] = true;
                }, 500);
                $violations = 0;
                $adapter->streamRows('public', $table['name'], [$fk['column']], function ($row) use (&$violations, $referencedIds, $fk) {
                    $value = $row[$fk['column']] ?? null;
                    if ($value !== null && ! isset($referencedIds[(string) $value])) {
                        $violations++;
                    }
                }, 500);
                if ($violations > 0) {
                    $fkIssues[] = ['table' => $table['name'], 'column' => $fk['column'], 'references' => $fk['references_table'], 'violations' => $violations];
                }
            }
        }

        return [
            'kind' => 'connector_validation',
            'connector_key' => self::KEY,
            'tables' => count($inventory['tables']),
            'row_counts' => $rowCounts,
            'foreign_key_issues' => $fkIssues,
            'source_fingerprint' => $this->fingerprint($source),
        ];
    }

    public function fingerprint(MigrationSource $source): string
    {
        return $this->sourceAdapter($source)->fingerprint();
    }
}
