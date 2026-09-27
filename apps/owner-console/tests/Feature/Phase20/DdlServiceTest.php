<?php

namespace Tests\Feature\Phase20;

use App\Models\Project;
use App\Models\ProjectSchemaChange;
use App\Models\User;
use App\Services\ControlPlane\DdlService;
use App\Services\ControlPlane\ProjectConnectionManager;
use App\Services\ControlPlane\ProjectDatabaseExplorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DdlServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['is_admin' => true]);
        $this->project = Project::create([
            'name' => 'Probe A', 'slug' => 'gate-a', 'status' => 'active',
            'db_name' => 'gate_a_db', 'redis_prefix' => 'gatea',
        ]);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    protected function cleanup(): void
    {
        $conn = ProjectConnectionManager::connection($this->project);
        foreach (['cp20_orders', 'cp20_customers'] as $t) {
            try {
                DB::connection($conn)->statement('DROP TABLE IF EXISTS "'.$t.'" CASCADE');
            } catch (\Throwable) {
            }
        }
    }

    public function test_visual_table_lifecycle(): void
    {
        DdlService::createTable($this->project, 'cp20_customers', [
            ['name' => 'id', 'type' => 'serial', 'nullable' => false, 'default' => null, 'pk' => true, 'unique' => false],
            ['name' => 'email', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'pk' => false, 'unique' => true],
        ]);
        DdlService::createTable($this->project, 'cp20_orders', [
            ['name' => 'id', 'type' => 'serial', 'nullable' => false, 'default' => null, 'pk' => true, 'unique' => false],
            ['name' => 'customer_id', 'type' => 'integer', 'nullable' => false, 'default' => null, 'pk' => false, 'unique' => false],
            ['name' => 'total', 'type' => 'numeric', 'nullable' => true, 'default' => '0', 'pk' => false, 'unique' => false],
        ]);

        DdlService::addColumn($this->project, 'cp20_orders', [
            'name' => 'note', 'type' => 'text', 'nullable' => true, 'default' => null, 'pk' => false, 'unique' => false,
        ]);

        DdlService::addForeignKey($this->project, 'cp20_orders', 'customer_id', 'cp20_customers', 'id');

        $explorer = ProjectDatabaseExplorer::for($this->project);
        $this->assertContains('cp20_orders', array_column($explorer->tables(), 'name'));
        $this->assertContains('note', array_column($explorer->columns('cp20_orders'), 'name'));
        $fks = $explorer->foreignKeys('cp20_orders');
        $this->assertSame('cp20_customers', $fks[0]['to_table']);

        // Traceable change records (20S).
        $this->assertTrue(
            ProjectSchemaChange::query()->where('project_id', $this->project->id)->where('kind', 'table_created')->exists()
        );

        DdlService::dropForeignKey($this->project, 'cp20_orders', 'fk_cp20_orders_customer_id');
        $this->assertSame([], ProjectDatabaseExplorer::for($this->project)->foreignKeys('cp20_orders'));

        DdlService::dropTable($this->project, 'cp20_orders');
        $this->assertNotContains('cp20_orders', array_column(ProjectDatabaseExplorer::for($this->project)->tables(), 'name'));
    }

    public function test_protected_tables_and_bad_identifiers_refused(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        DdlService::dropTable($this->project, 'migrations');
    }

    public function test_invalid_identifier_refused(): void
    {
        try {
            DdlService::createTable($this->project, 'evil"; DROP TABLE users; --', [
                ['name' => 'id', 'type' => 'serial', 'nullable' => false, 'default' => null, 'pk' => true, 'unique' => false],
            ]);
            $this->fail('Expected 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }
}
