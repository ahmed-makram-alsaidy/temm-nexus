<?php

namespace Tests\Feature\Phase20;

use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\DbAdvancedService;
use App\Services\ControlPlane\DbFunctionService;
use App\Services\ControlPlane\ProjectConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DbAdvancedTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->project = Project::create([
            'name' => 'Probe A', 'slug' => 'gate-a', 'status' => 'active',
            'db_name' => 'gate_a_db', 'redis_prefix' => 'gatea',
        ]);
        $conn = ProjectConnectionManager::connection($this->project);
        DB::connection($conn)->statement('DROP TABLE IF EXISTS cp20_idx_t CASCADE');
        DB::connection($conn)->statement('CREATE TABLE cp20_idx_t (id serial primary key, email text, age integer)');
    }

    protected function tearDown(): void
    {
        try {
            $conn = ProjectConnectionManager::connection($this->project);
            DB::connection($conn)->statement('DROP TRIGGER IF EXISTS cp20_trg ON cp20_idx_t');
            foreach (['cp20_add_fn', 'cp20_trg_fn'] as $fn) {
                foreach (DB::connection($conn)->select(
                    "SELECT p.oid FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                      WHERE n.nspname='public' AND p.proname=?", [$fn]
                ) as $row) {
                    DbFunctionService::drop($this->project, (int) $row->oid);
                }
            }
            DB::connection($conn)->statement('DROP TABLE IF EXISTS cp20_idx_t CASCADE');
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function test_index_inventory_and_creation(): void
    {
        $before = DbAdvancedService::indexes($this->project);
        $this->assertNotEmpty($before); // pkey always present

        $name = DbAdvancedService::createIndex($this->project, 'cp20_idx_t', ['email'], false);
        $names = array_column(DbAdvancedService::indexes($this->project), 'name');
        $this->assertContains($name, $names);
    }

    public function test_trigger_lifecycle_with_catalog_function(): void
    {
        DbFunctionService::save(
            $this->project, 'cp20_trg_fn', '', 'trigger', 'plpgsql', 'INVOKER',
            'BEGIN NEW.email := lower(NEW.email); RETURN NEW; END'
        );
        DbAdvancedService::createTrigger($this->project, 'cp20_trg', 'cp20_idx_t', 'BEFORE', 'INSERT', 'cp20_trg_fn');

        $triggers = DbAdvancedService::triggers($this->project);
        $found = array_values(array_filter($triggers, fn ($t) => $t['name'] === 'cp20_trg'));
        $this->assertNotEmpty($found);
        $this->assertSame('cp20_idx_t', $found[0]['table']);

        // Trigger actually fires.
        $conn = ProjectConnectionManager::connection($this->project);
        DB::connection($conn)->table('cp20_idx_t')->insert(['email' => 'UP@X.TEST', 'age' => 1]);
        $this->assertSame('up@x.test', DB::connection($conn)->table('cp20_idx_t')->value('email'));

        DbAdvancedService::dropTrigger($this->project, 'cp20_idx_t', 'cp20_trg');
        $this->assertSame(
            [],
            array_values(array_filter(DbAdvancedService::triggers($this->project), fn ($t) => $t['name'] === 'cp20_trg'))
        );
    }

    public function test_extensions_and_authorization_report(): void
    {
        $ext = DbAdvancedService::extensions($this->project);
        $this->assertArrayHasKey('pg_stat_statements', $ext['allowlist']);

        $authz = DbAdvancedService::authorization($this->project);
        $this->assertNotEmpty($authz['roles']);
        $this->assertStringContainsString('Laravel layer', $authz['note']);

        // Non-allowlisted extension refused without touching the DB.
        try {
            DbAdvancedService::installExtension($this->project, 'evil_ext');
            $this->fail('Expected 403');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_erd_and_page_render(): void
    {
        $graph = DbAdvancedService::erd($this->project);
        $this->assertContains('cp20_idx_t', $graph['tables']);
        $svg = DbAdvancedService::erdSvg($graph);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('cp20_idx_t', $svg);

        $this->actingAs($this->admin)
            ->get('/admin/projects/'.$this->project->id.'/db-advanced')
            ->assertOk();
    }
}
