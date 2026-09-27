<?php

namespace Tests\Feature\Phase24;

use App\Models\MigrationPlan;
use App\Models\MigrationPlanItem;
use App\Models\MigrationSource;
use App\Models\ResourceMetricSample;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase24\Concerns\BuildsEngineFixture;
use Tests\TestCase;

/**
 * Phase 24 performance: large inventories must not freeze the UI or the
 * engine. Synthetic scale: 100 tables, 500+ policies/functions, 10k plan
 * item records, 10k metric samples — with paginated reads.
 */
class PerformanceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEngineFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBase();
        $this->actingAs($this->admin);
    }

    public function test_100_table_inventory_analyzes_and_reads_paged(): void
    {
        $path = $this->tempPath('perf-source-'.uniqid().'.sqlite');
        if (file_exists($path)) {
            unlink($path);
        }
        $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        for ($i = 0; $i < 100; $i++) {
            $pdo->exec("CREATE TABLE perf_table_{$i} (id INTEGER PRIMARY KEY, name TEXT)");
            $pdo->exec("INSERT INTO perf_table_{$i} (name) VALUES ('row-{$i}')");
        }

        $source = MigrationSource::create([
            'project_id' => $this->projectA->id, 'type' => 'sqlite', 'display_name' => '100-table perf fixture',
            'connection' => ['path' => $path], 'read_only' => true, 'status' => 'pending',
        ]);

        $started = microtime(true);
        $analysis = (new MigrationCenterService)->analyze($source);
        $elapsed = microtime(true) - $started;

        $this->assertSame('completed', $analysis->status);
        $this->assertSame(100, $analysis->items()->where('kind', 'table')->count());
        $this->assertLessThan(30.0, $elapsed, "analysis of 100 tables took {$elapsed}s");

        // Paged reads stay bounded (UI contract).
        $page = $analysis->items()->where('kind', 'table')->orderBy('name')->limit(50)->get();
        $this->assertCount(50, $page);
    }

    public function test_10k_plan_items_paginate_quickly(): void
    {
        $source = MigrationSource::create([
            'project_id' => $this->projectA->id, 'type' => 'sqlite', 'display_name' => 'perf',
            'connection' => ['path' => ':memory:'], 'read_only' => true, 'status' => 'pending',
        ]);
        $analysisRow = \App\Models\MigrationAnalysis::create([
            'project_id' => $this->projectA->id, 'migration_source_id' => $source->id, 'run_id' => 'perf', 'status' => 'completed',
        ]);
        $plan = MigrationPlan::create([
            'project_id' => $this->projectA->id,
            'migration_analysis_id' => $analysisRow->id,
            'name' => 'perf plan', 'status' => 'draft',
        ]);

        $rows = [];
        for ($i = 0; $i < 10000; $i++) {
            $rows[] = [
                'migration_plan_id' => $plan->id,
                'source_kind' => 'table', 'source_schema' => 'public', 'source_name' => "t{$i}",
                'target_kind' => 'table', 'target_name' => "t{$i}",
                'strategy' => 'direct_copy', 'transform' => 'direct_copy', 'stage' => $i % 10,
                'validation' => 'row_counts', 'status' => 'READY',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('migration_plan_items')->insert($chunk);
        }

        $started = microtime(true);
        $page = MigrationPlanItem::where('migration_plan_id', $plan->id)->where('stage', 3)->orderBy('id')->limit(50)->get();
        $count = MigrationPlanItem::where('migration_plan_id', $plan->id)->count();
        $elapsed = microtime(true) - $started;

        $this->assertSame(10000, $count);
        $this->assertCount(50, $page);
        $this->assertLessThan(2.0, $elapsed, "paged read over 10k items took {$elapsed}s");
    }

    public function test_10k_metric_samples_aggregate_quickly(): void
    {
        $rows = [];
        for ($i = 0; $i < 10000; $i++) {
            $rows[] = [
                'project_id' => $this->projectA->id, 'metric' => 'db_size_bytes',
                'value' => 1000 + $i, 'unit' => 'bytes', 'attribution' => 'project',
                'sampled_at' => now()->subMinutes(10000 - $i),
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('resource_metric_samples')->insert($chunk);
        }

        $started = microtime(true);
        $latest = ResourceMetricSample::where('project_id', $this->projectA->id)
            ->where('metric', 'db_size_bytes')->orderByDesc('sampled_at')->first();
        $elapsed = microtime(true) - $started;

        $this->assertNotNull($latest);
        $this->assertLessThan(1.0, $elapsed, "latest-sample lookup over 10k rows took {$elapsed}s");
    }
}
