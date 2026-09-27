<?php

namespace Tests\Feature\Phase20;

use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\DbFunctionService;
use App\Services\ControlPlane\ProjectConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DbFunctionServiceTest extends TestCase
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
        $this->dropQuietly('cp20_add_two');
        $this->dropQuietly('cp20_hello');
    }

    protected function tearDown(): void
    {
        $this->dropQuietly('cp20_add_two');
        $this->dropQuietly('cp20_hello');
        parent::tearDown();
    }

    protected function dropQuietly(string $name): void
    {
        try {
            $conn = ProjectConnectionManager::connection($this->project);
            foreach (DB::connection($conn)->select(
                "SELECT p.oid FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                  WHERE n.nspname='public' AND p.proname=?",
                [$name]
            ) as $row) {
                DbFunctionService::drop($this->project, (int) $row->oid);
            }
        } catch (\Throwable) {
        }
    }

    public function test_create_invoke_delete_sql_function(): void
    {
        $saved = DbFunctionService::save(
            $this->project, 'cp20_add_two', 'a integer, b integer',
            'integer', 'sql', 'INVOKER', 'SELECT a + b'
        );
        $this->assertGreaterThan(0, $saved['oid']);

        $names = array_column(DbFunctionService::list($this->project), 'name');
        $this->assertContains('cp20_add_two', $names);

        $result = DbFunctionService::invoke($this->project, $saved['oid'], [20, 22]);
        $this->assertTrue($result['ok']);
        $this->assertSame(42, (int) array_values($result['rows'][0])[0]);

        $this->assertSame('cp20_add_two', DbFunctionService::drop($this->project, $saved['oid']));
        $this->assertNotContains('cp20_add_two', array_column(DbFunctionService::list($this->project), 'name'));
    }

    public function test_plpgsql_function_with_definer_flagged(): void
    {
        $saved = DbFunctionService::save(
            $this->project, 'cp20_hello', 'who text', 'text', 'plpgsql', 'DEFINER',
            'BEGIN RETURN \'hi \' || who; END'
        );
        $list = DbFunctionService::list($this->project);
        $found = array_values(array_filter($list, fn ($f) => $f['name'] === 'cp20_hello'))[0];
        $this->assertSame('DEFINER', $found['security']);

        $result = DbFunctionService::invoke($this->project, $saved['oid'], ['platform']);
        $this->assertTrue($result['ok']);
        $this->assertSame('hi platform', array_values($result['rows'][0])[0]);
    }

    public function test_bad_names_and_languages_refused(): void
    {
        try {
            DbFunctionService::save($this->project, 'evil x', '', 'void', 'sql', 'INVOKER', 'SELECT 1');
            $this->fail('Expected 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        try {
            DbFunctionService::save($this->project, 'cp20_x', '', 'void', 'python', 'INVOKER', 'SELECT 1');
            $this->fail('Expected 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_page_renders_for_permitted_user(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)
            ->get('/admin/projects/'.$this->project->id.'/db-functions')
            ->assertOk();
    }
}
