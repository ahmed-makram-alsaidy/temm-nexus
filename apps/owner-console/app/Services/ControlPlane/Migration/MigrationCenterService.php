<?php

namespace App\Services\ControlPlane\Migration;

use App\Models\MigrationAnalysis;
use App\Models\MigrationAnalysisItem;
use App\Models\MigrationSource;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\Connectors\ConnectorCapabilityProbe;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Migration\SourceAdapters\SqliteSourceAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 24A orchestrator: analyze → versioned snapshot → compatibility → risks →
 * artifacts. Every analyze run is an immutable new analysis row (history is
 * never overwritten).
 *
 * Phase 27D.2/27I — adapters are resolved through the connector registry
 * (never a provider map); every analysis records connector key/version and
 * the normalized analysis schema version (27I.2 traceability).
 */
class MigrationCenterService
{
    public const ANALYSIS_VERSION = 'analysis@1';

    /**
     * Legacy adapter map — kept only for the SQLite rehearsal source, which
     * is an engine fixture, not a provider plugin. All real connectors come
     * from the registry (27D).
     */
    protected array $adapters = [
        'sqlite' => SqliteSourceAdapter::class,
    ];

    public function registerAdapter(string $id, string $class): void
    {
        $this->adapters[$id] = $class;
    }

    public function makeAdapter(MigrationSource $source): object
    {
        // Phase 27 — provider connectors resolve through the registry.
        if (! in_array($source->effectiveConnectorKey(), array_keys($this->adapters), true)) {
            return ConnectorRegistry::instance()->resolveSource($source);
        }
        $class = $this->adapters[$source->effectiveConnectorKey()];
        abort_if($class === null, 422, "No source adapter for type '{$source->effectiveConnectorKey()}'");

        return new $class($source);
    }

    /** Analyze a source: full read-only inventory, versioned. */
    public function analyze(MigrationSource $source): MigrationAnalysis
    {
        $runId = (string) Str::uuid();
        $connector = null;
        $connectorKey = $source->effectiveConnectorKey();
        $connectorVersion = null;
        try {
            $connector = ConnectorRegistry::instance()->connectorForSource($source);
            $connectorVersion = $connector->manifest()->version();
        } catch (\Throwable) {
            // Unknown connector still records the raw key — analysis will fail
            // on adapter resolution below with the honest error.
        }
        $analysis = MigrationAnalysis::create([
            'project_id' => $source->project_id,
            'migration_source_id' => $source->id,
            'run_id' => $runId,
            'status' => 'running',
            'started_at' => now(),
            'driver_version' => 'engine@1',
            // Phase 27I.2 — connector traceability.
            'connector_key' => $connectorKey,
            'connector_version' => $connectorVersion,
            'analysis_version' => self::ANALYSIS_VERSION,
        ]);

        try {
            $started = microtime(true);
            $adapter = $this->makeAdapter($source);
            $adapter->connect();
            $inventory = $adapter->inventory();
            $fingerprint = SchemaFingerprint::compute($inventory);
            $adapter->close();
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            \App\Services\ControlPlane\Connectors\Support\ConnectorLogger::operation($source, 'analyze', [
                'run_id' => $runId, 'duration_ms' => $durationMs, 'items' => collect($inventory)->map(fn ($d) => is_countable($d) ? count($d) : 0)->sum(),
            ]);

            $counts = [];
            $warnings = [];

            foreach ($inventory as $kind => $data) {
                if (in_array($kind, ['auth', 'storage', 'realtime'], true)) {
                    if (($data['present'] ?? true) === false) {
                        $warnings[] = ucfirst($kind).' domain not present on source';
                        $counts[$kind] = 0;
                    } else {
                        $counts[$kind] = $kind === 'auth' ? ($data['users_count'] ?? 0) : count($data['buckets'] ?? []);
                    }
                    $this->storeItem($analysis, $kind, $kind === 'auth' ? 'auth' : $kind, $kind, $data);
                    continue;
                }
            $counts[$kind] = is_array($data) ? count($data) : 0;
            if (! is_array($data)) {
                continue;
            }
            // Normalize inventory keys to singular item kinds.
            $itemKind = match ($kind) {
                'tables' => 'table', 'views' => 'view', 'matviews' => 'matview',
                'enums' => 'enum', 'functions' => 'function', 'triggers' => 'trigger',
                'policies' => 'policy', 'extensions' => 'extension',
                'edge_functions' => 'edge_function', 'client_dependencies' => 'client',
                default => $kind,
            };
            if ($kind === 'tables') {
                foreach ($data as $t) {
                    $this->storeItem($analysis, $itemKind, $t['schema'] ?? 'public', $t['name'], $t);
                }
                continue;
            }
            foreach ($data as $item) {
                $name = is_array($item) ? ($item['name'] ?? '?') : (string) $item;
                $attrs = is_array($item) ? $item : ['value' => $item];
                $this->storeItem($analysis, $itemKind, $attrs['schema'] ?? null, $name, $attrs);
            }
            }

            $analysis->update([
                'status' => 'completed',
                'completed_at' => now(),
                'counts' => $counts,
                'warnings' => $warnings,
                'errors' => [],
                'source_fingerprint' => $fingerprint,
            ]);

            $source->update(['status' => 'ready', 'last_analyzed_at' => now(), 'last_error' => null]);
            $source->stampConnector($connectorKey, $connectorVersion);
            $this->writeArtifact($analysis, 'analysis', $inventory, [
                'counts' => $counts,
                'fingerprint' => $fingerprint,
                'duration_ms' => $durationMs,
                // Phase 27I.2 — artifact traceability.
                'connector_key' => $connectorKey,
                'connector_version' => $connectorVersion,
                'analysis_version' => self::ANALYSIS_VERSION,
            ]);
            AdminAudit::record('MIGRATION_ANALYSIS_RUN', $source->project, 'migration_analysis', $analysis->id, [
                'source' => $source->display_name, 'run_id' => $runId, 'counts' => $counts,
            ]);
        } catch (\Throwable $e) {
            $analysis->update([
                'status' => 'failed', 'completed_at' => now(),
                'errors' => ['message' => $e->getMessage()],
            ]);
            $source->update(['status' => 'error', 'last_error' => Str::limit($e->getMessage(), 300)]);
        }

        return $analysis;
    }

    /** Classify + risk-flag all items of an analysis (24A.5 + 24A.6). */
    public function classify(MigrationAnalysis $analysis): void
    {
        $analysis->items()->chunkById(200, function ($items) {
            foreach ($items as $item) {
                [$compat, $note] = CompatibilityClassifier::classify($item->kind, $item->attributes ?? []);
                $risks = RiskDetector::detect($item->kind, $item->attributes ?? []);
                $item->update(['compatibility' => $compat, 'risks' => $risks]);
            }
        });
    }

    /** 24J.7 — RLS → Laravel authorization mapping artifact (never auto-applied). */
    public function rlsMappingArtifact(MigrationAnalysis $analysis): array
    {
        $policies = $analysis->items()->where('kind', 'policy')->get();
        $mapping = [];
        $testMatrix = [];
        foreach ($policies as $policy) {
            $table = $policy->attributes['table'] ?? null;
            $command = $policy->attributes['command'] ?? 'ALL';
            $authDep = in_array('rls_auth_dependency', $policy->risks ?? [], true);
            $candidate = [
                'policy' => $policy->name,
                'table' => $table,
                'command' => $command,
                'candidate' => $authDep ? 'Laravel Policy with authenticated user + tenant scope' : 'Laravel Gate or Policy (static condition)',
                'model' => $table ? Str::studly(Str::singular($table)) : null,
                'requires_review' => true,
            ];
            $mapping[] = $candidate;
            // Required test matrix: ALLOW + DENY per policy (lesson from RLS
            // dual-stack equivalence work).
            $testMatrix[] = [
                'policy' => $policy->name,
                'table' => $table,
                'tests' => [
                    ['kind' => 'ALLOW', 'actor' => 'authorized role/tenant member', 'expect' => 'row visible/writable'],
                    ['kind' => 'DENY', 'actor' => 'unrelated role/tenant', 'expect' => 'row hidden/403'],
                ],
            ];
        }
        $artifact = [
            'kind' => 'rls_mapping',
            'analysis_run_id' => $analysis->run_id,
            'policies' => $mapping,
            'test_matrix' => $testMatrix,
            'note' => 'Candidate mappings only — complex policies are never auto-converted.',
        ];
        $this->writeArtifact($analysis, 'mapping', $artifact, ['policies' => count($mapping)]);

        return $artifact;
    }

    /** 24A.7 — generate the standard plan for an analysis. */
    public function generatePlan(MigrationAnalysis $analysis): \App\Models\MigrationPlan
    {
        $plan = (new PlanGenerator)->generate($analysis);
        AdminAudit::record('MIGRATION_PLAN_CREATED', $analysis->project, 'migration_plan', $plan->id, [
            'items' => $plan->items()->count(),
        ]);

        return $plan;
    }

    /**
     * Dogfood comparison (REAL DOGFOOD TEST): documented inventory vs the
     * new automatic inventory. Returns per-kind expected/found + delta.
     */
    public function compareWithDocumented(MigrationAnalysis $analysis, array $documented): array
    {
        $rows = [];
        $map = [
            'tables' => 'table', 'policies' => 'policy', 'functions' => 'function',
            'triggers' => 'trigger', 'views' => 'view', 'enums' => 'enum',
            'cron' => 'cron', 'edge_functions' => 'edge_function',
        ];
        foreach ($map as $docKey => $kind) {
            if (! array_key_exists($docKey, $documented)) {
                continue;
            }
            $found = $analysis->items()->where('kind', $kind)->count();
            $expected = (int) $documented[$docKey];
            $rows[$docKey] = [
                'documented' => $expected,
                'rediscovered' => $found,
                'coverage_percent' => $expected > 0 ? round(min(100, $found / $expected * 100), 1) : null,
                'delta' => $found - $expected,
            ];
        }
        if (isset($documented['auth_users'])) {
            $authItem = $analysis->items()->where('kind', 'auth')->first();
            $rows['auth_users'] = [
                'documented' => (int) $documented['auth_users'],
                'rediscovered' => $authItem->attributes['users_count'] ?? 0,
                'coverage_percent' => null,
                'delta' => ($authItem->attributes['users_count'] ?? 0) - (int) $documented['auth_users'],
            ];
        }
        if (isset($documented['buckets'])) {
            $storageItem = $analysis->items()->where('kind', 'storage')->first();
            $rows['storage_buckets'] = [
                'documented' => (int) $documented['buckets'],
                'rediscovered' => count($storageItem->attributes['buckets'] ?? []),
                'coverage_percent' => null,
                'delta' => count($storageItem->attributes['buckets'] ?? []) - (int) $documented['buckets'],
            ];
        }

        return $rows;
    }

    protected function storeItem(MigrationAnalysis $analysis, string $kind, ?string $schema, string $name, array $attributes): void
    {
        MigrationAnalysisItem::create([
            'migration_analysis_id' => $analysis->id,
            'kind' => $kind,
            'schema_name' => $schema,
            'name' => $name,
            'attributes' => $attributes,
        ]);
    }

    /** Artifacts live in private storage, gitignored, never public. */
    protected function writeArtifact(MigrationAnalysis $analysis, string $kind, array $payload, array $summary): void
    {
        $path = sprintf('control-plane/migration-artifacts/%d/%s-%s.json', $analysis->project_id, $kind, $analysis->run_id);
        Storage::disk('local')->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        \App\Models\MigrationArtifact::create([
            'project_id' => $analysis->project_id,
            'migration_analysis_id' => $analysis->id,
            'kind' => $kind,
            'path' => $path,
            'summary' => $summary,
        ]);
    }
}
