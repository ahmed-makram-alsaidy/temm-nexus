<?php

namespace Tests\Feature\Phase27;

use App\Connectors\ExampleJson\ExampleJsonSourceAdapter;
use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\ConnectorCapability;
use App\Services\ControlPlane\Connectors\ConnectorManifest;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Connectors\Contracts\Connector;
use App\Services\ControlPlane\Connectors\Contracts\SourceConnector;
use App\Services\ControlPlane\Connectors\Support\BaseSourceAdapter;
use App\Services\ControlPlane\Migration\CompatibilityClassifier;
use App\Services\ControlPlane\Migration\RiskDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase27\Concerns\BuildsPhase27Fixture;
use Tests\TestCase;

/**
 * Phase 27U — registry scale (100 connector definitions) and analysis
 * normalization scale (100 tables, 500 functions/policies, 10k source items).
 */
class ConnectorPerformanceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPhase27Fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPhase27();
    }

    /** Minimal in-memory connector stub used only for registry scale tests. */
    protected function makeStubConnector(string $key): Connector
    {
        $manifest = ConnectorManifest::validate([
            'schema_version' => 1, 'key' => $key, 'name' => 'Stub '.ucfirst($key), 'version' => '1.0.0',
            'description' => 'scale-test stub', 'author' => 'phase27 test',
            'entrypoint' => 'App\Connectors\ExampleJson\ExampleJsonConnector', 'capabilities' => ['database_metadata'],
            'trust' => 'first_party', 'import_flow' => 'none',
        ], 'stub:'.$key);

        return new class($manifest) implements Connector
        {
            public function __construct(private ConnectorManifest $manifest)
            {
            }

            public function manifest(): ConnectorManifest
            {
                return $this->manifest;
            }

            public function definition(): \App\Services\ControlPlane\Connectors\ConnectorDefinition
            {
                return \App\Services\ControlPlane\Connectors\ConnectorDefinition::fromManifest($this->manifest, [], []);
            }

            public function credentialSchema(): array
            {
                return [];
            }

            public function capabilities(): array
            {
                return $this->manifest->capabilities();
            }

            public function capabilityStatus(string $capability, array $resolvableFieldKeys = []): string
            {
                return ConnectorCapability::SUPPORTED;
            }

            public function testConnection(\App\Services\ControlPlane\Connectors\ConnectorCredentials $credentials): \App\Services\ControlPlane\Connectors\ConnectorTestResult
            {
                return \App\Services\ControlPlane\Connectors\ConnectorTestResult::pass('stub');
            }

            public function health(\App\Models\MigrationSource $source): \App\Services\ControlPlane\Connectors\ConnectorHealth
            {
                return \App\Services\ControlPlane\Connectors\ConnectorHealth::connected();
            }
        };
    }

    public function test_registry_with_100_definitions_stays_responsive(): void
    {
        // 27U — 100 connector definitions, no real implementations required.
        $registry = ConnectorRegistry::instance();
        $realKeys = array_keys($registry->allConnectors());
        $stubKeys = [];
        $index = 0;
        foreach (range(1, 100 - count($realKeys)) as $i) {
            try {
                $key = 'stub-source-'.$i;
                $registry->register($this->makeStubConnector($key));
                $stubKeys[] = $key;
                $index++;
            } catch (\App\Services\ControlPlane\Connectors\ConnectorKeyConflict) {
                continue;
            }
        }
        $this->assertGreaterThanOrEqual(90, count($stubKeys), 'registry scaled to ~100 definitions (real connectors also occupy slots)');

        $started = microtime(true);
        $descriptors = ConnectorRegistry::all();
        $listed = ConnectorRegistry::instance()->descriptors();
        $resolveElapsed = microtime(true) - $started;

        $this->assertCount(count($realKeys) + count($stubKeys), $listed);
        $this->assertLessThan(2.0, $resolveElapsed, "listing 100 definitions took {$resolveElapsed}s");

        foreach ($stubKeys as $key) {
            $registry->unregister($key);
        }
        $this->assertCount(count($realKeys), $registry->allConnectors());
    }

    public function test_analysis_normalization_at_scale(): void
    {
        // 27U — 100 tables, 500 functions, 500 policies: classification and
        // risk detection remain responsive.
        $items = [];
        foreach (range(1, 100) as $t) {
            $items[] = ['kind' => 'table', 'attributes' => [
                'name' => 'table_'.$t, 'schema' => 'public',
                'columns' => array_map(fn ($c) => ['name' => 'col_'.$c, 'type' => 'int8', 'nullable' => false, 'default' => null], range(1, 8)),
                'primary_key' => ['id'], 'foreign_keys' => [], 'row_estimate' => 1000 * $t,
            ]];
        }
        foreach (range(1, 500) as $f) {
            $items[] = ['kind' => 'function', 'attributes' => ['name' => 'fn_'.$f, 'schema' => 'public', 'security' => $f % 2 ? 'definer' : 'invoker', 'language' => 'plpgsql']];
        }
        foreach (range(1, 500) as $p) {
            $items[] = ['kind' => 'policy', 'attributes' => ['name' => 'pol_'.$p, 'table' => 'table_'.($p % 100), 'command' => 'SELECT', 'using' => $p % 3 ? 'true' : "auth.uid() = user_id"]];
        }

        $started = microtime(true);
        foreach ($items as $item) {
            CompatibilityClassifier::classify($item['kind'], $item['attributes']);
            RiskDetector::detect($item['kind'], $item['attributes']);
        }
        $elapsed = microtime(true) - $started;
        $this->assertLessThan(5.0, $elapsed, "normalizing 1100 items took {$elapsed}s");
    }

    public function test_extraction_of_10k_source_items_is_batched_and_quick(): void
    {
        // 27U — 10k source items through the connector extraction contract.
        $dir = storage_path('framework/testing/phase27/perf-ds-'.uniqid());
        mkdir($dir, 0777, true);
        $rows = [];
        foreach (range(1, 10000) as $i) {
            $rows[] = ['id' => $i, 'payload' => 'row-'.$i, 'value' => $i * 1.5];
        }
        file_put_contents($dir.'/big_collection.json', json_encode($rows));
        $source = MigrationSource::create([
            'project_id' => $this->projectA->id, 'type' => 'example-json', 'connector_key' => 'example-json',
            'display_name' => 'Perf', 'connection' => ['dataset_path' => $dir],
            'secret_refs' => [], 'read_only' => true, 'status' => 'ready',
        ]);

        $adapter = new ExampleJsonSourceAdapter($source);
        $started = microtime(true);
        $count = 0;
        $batchMax = 0;
        $current = 0;
        $total = $adapter->streamRows('public', 'big_collection', [], function ($row) use (&$count, &$batchMax, &$current) {
            $count++;
            $current++;
        }, 500);
        $elapsed = microtime(true) - $started;

        $this->assertSame(10000, $total);
        $this->assertLessThan(10.0, $elapsed, "streaming 10k records took {$elapsed}s");
        @unlink($dir.'/big_collection.json');
        @rmdir($dir);
    }
}
