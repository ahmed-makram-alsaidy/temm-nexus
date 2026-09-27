<?php

namespace Tests\Feature\Phase28;

use App\Connectors\Mongodb\MongodbSourceAdapter;
use App\Models\MigrationSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase27\Concerns\BuildsPhase27Fixture;
use Tests\Feature\Phase28\Concerns\RunsFakeMongoServer;
use Tests\TestCase;

/**
 * Phase 28U — synthetic performance: 100 collections metadata, 1,000
 * inferred fields, 10,000 documents extraction — with bounded memory
 * (28U.1: the full collection is never materialized client-side beyond the
 * strategy projection stream). Each scenario runs its own scripted server.
 */
class MongodbPerformanceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPhase27Fixture;
    use RunsFakeMongoServer;

    /** @var resource|null */
    protected $perfProcess = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPhase27();
        $this->actingAs($this->admin);
        config(['connectors.allow_private_networks' => true]);
    }

    protected function tearDown(): void
    {
        $this->stopFakeMongo();
        if (is_resource($this->perfProcess)) {
            proc_terminate($this->perfProcess);
            proc_close($this->perfProcess);
            $this->perfProcess = null;
        }
        parent::tearDown();
    }

    /** Spawn a dedicated fake server for one dataset; returns its URI. */
    protected function startDatasetServer(array $dataset): string
    {
        $dir = storage_path('framework/testing/phase28/perf-ds-'.uniqid());
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir.'/dataset.php', '<?php return '.var_export($dataset, true).';');
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', storage_path('framework/testing/phase28/perf-server-err.log'), 'a']];
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        $this->perfProcess = proc_open(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/Concerns/fake-mongo-server.php').' '.escapeshellarg($dir.'/dataset.php').' '.$port, $spec, $pipes);
        fgets($pipes[1], 128);

        return 'mongodb://127.0.0.1:'.$port.'/shop';
    }

    protected function makeSourceWith(string $uri, int $sampleSize): MigrationSource
    {
        \App\Services\ControlPlane\SecretVaultService::createSecret($this->projectA, 'MONGODB_URI', $uri, ['category' => 'database']);

        return MigrationSource::create([
            'project_id' => $this->projectA->id,
            'type' => 'mongodb', 'connector_key' => 'mongodb',
            'display_name' => 'Perf source',
            'connection' => ['database' => 'shop', 'sample_size' => $sampleSize],
            'secret_refs' => ['uri' => 'MONGODB_URI'],
            'read_only' => true, 'status' => 'pending',
        ]);
    }

    public function test_100_collections_metadata_stays_responsive(): void
    {
        $uri = $this->startDatasetServer($this->manyCollectionsDataset(100));
        $adapter = new MongodbSourceAdapter($this->makeSourceWith($uri, 5));

        $started = microtime(true);
        $inventory = $adapter->inventory();
        $elapsed = microtime(true) - $started;

        $this->assertCount(100, $inventory['tables'], 'all 100 collections inventoried');
        $this->assertLessThan(30.0, $elapsed, "100-collection analysis took {$elapsed}s");
        $adapter->close();
    }

    public function test_1000_inferred_fields_normalize_quickly(): void
    {
        $uri = $this->startDatasetServer($this->wideCollectionDataset(1000));
        $adapter = new MongodbSourceAdapter($this->makeSourceWith($uri, 50));

        $started = microtime(true);
        $inventory = $adapter->inventory();
        $elapsed = microtime(true) - $started;

        $wide = collect($inventory['tables'])->firstWhere('name', 'wide');
        // >60 fields flips the strategy to JSONB_DOCUMENT (28I.2): the 1000
        // inferred fields collapse into [_id, document] by design.
        $this->assertSame('JSONB_DOCUMENT', $wide['migration_strategy'], 'wide collection mapped to JSONB (28I.2)');
        $this->assertSame(['_id', 'document'], array_column($wide['columns'], 'name'));
        $this->assertLessThan(30.0, $elapsed, "1000-field normalization took {$elapsed}s");
        $adapter->close();
    }

    public function test_10k_document_extraction_is_batched_and_bounded(): void
    {
        $uri = $this->startDatasetServer($this->largeCollectionDataset(10000));
        $adapter = new MongodbSourceAdapter($this->makeSourceWith($uri, 50));
        $adapter->inventory(); // warm analysis

        $peakBefore = memory_get_peak_usage(true);
        $started = microtime(true);
        $written = $adapter->streamRows('shop', 'big_collection', [], function ($row) {
        }, 1000);
        $elapsed = microtime(true) - $started;
        $peakDelta = (memory_get_peak_usage(true) - $peakBefore) / 1048576;

        $this->assertSame(10000, $written);
        $this->assertLessThan(60.0, $elapsed, "10k extraction took {$elapsed}s");
        $this->assertLessThan(64.0, $peakDelta, "extraction grew peak memory by {$peakDelta}MB — unbounded buffering (28U.1)");
        $this->assertGreaterThan(1, $adapter->lastExtractionBatches, 'multiple server-side batches (28U.1)');
        $adapter->close();
    }

    // ── dataset builders (server-side fixtures) ─────────────────────────

    protected function manyCollectionsDataset(int $count): array
    {
        $collections = [];
        foreach (range(1, $count) as $i) {
            $collections["coll_{$i}"] = ['documents' => [
                ['_id' => static::oid($i), 'name' => "doc {$i}", 'value' => $i],
            ], 'indexes' => []];
        }

        return ['databases' => ['shop' => ['collections' => $collections]]];
    }

    protected function wideCollectionDataset(int $fields): array
    {
        $doc = ['_id' => static::oid(1)];
        foreach (range(1, $fields) as $f) {
            $doc["field_{$f}"] = $f;
        }

        return ['databases' => ['shop' => ['collections' => ['wide' => ['documents' => [$doc], 'indexes' => []]]]]];
    }

    protected function largeCollectionDataset(int $count): array
    {
        $docs = [];
        foreach (range(1, $count) as $i) {
            $docs[] = ['_id' => static::oid($i), 'payload' => "row-{$i}", 'value' => $i * 1.5];
        }

        return ['databases' => ['shop' => ['collections' => ['big_collection' => ['documents' => $docs, 'indexes' => []]]]]];
    }
}
