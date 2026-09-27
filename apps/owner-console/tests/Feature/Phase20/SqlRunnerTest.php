<?php

namespace Tests\Feature\Phase20;

use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\ProjectConnectionManager;
use App\Services\ControlPlane\SqlRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqlRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected Project $projectB;

    protected function conn(): string
    {
        return ProjectConnectionManager::connection($this->project);
    }

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['is_admin' => true]);
        $this->project = Project::create([
            'name' => 'Probe A', 'slug' => 'gate-a', 'status' => 'active',
            'db_name' => 'gate_a_db', 'redis_prefix' => 'gatea',
        ]);
        $this->projectB = Project::create([
            'name' => 'Probe B', 'slug' => 'gate-b', 'status' => 'active',
            'db_name' => 'gate_b_db', 'redis_prefix' => 'gateb',
        ]);
        DB::connection($this->conn())->statement(
            'CREATE TABLE IF NOT EXISTS cp20_sql_t (id serial primary key, name text, qty integer)'
        );
        DB::connection($this->conn())->table('cp20_sql_t')->delete();
        DB::connection($this->conn())->table('cp20_sql_t')->insert([
            ['name' => 'alpha', 'qty' => 3], ['name' => 'beta', 'qty' => 7],
        ]);
    }

    protected function tearDown(): void
    {
        try {
            DB::connection($this->conn())->statement('DROP TABLE IF EXISTS cp20_sql_t');
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function test_select_join_explain_work(): void
    {
        $r = SqlRunner::run($this->project, 'SELECT * FROM cp20_sql_t ORDER BY id');
        $this->assertSame('ok', $r['status']);
        $this->assertCount(2, $r['rows']);
        $this->assertContains('name', $r['columns']);

        $r = SqlRunner::run($this->project, 'SELECT a.name FROM cp20_sql_t a JOIN cp20_sql_t b ON b.id = a.id WHERE a.qty > 5');
        $this->assertSame('ok', $r['status']);
        $this->assertSame('beta', $r['rows'][0]['name']);

        $r = SqlRunner::run($this->project, 'EXPLAIN SELECT * FROM cp20_sql_t');
        $this->assertSame('ok', $r['status']);
        $this->assertNotEmpty($r['rows']);
    }

    public function test_error_renders_safely(): void
    {
        $r = SqlRunner::run($this->project, 'SELECT * FROM cp20_nope_missing');
        $this->assertSame('error', $r['status']);
        $this->assertNotEmpty($r['error']);
    }

    public function test_read_mode_cannot_write_even_if_attempted(): void
    {
        // Classifier blocks it…
        $r = SqlRunner::run($this->project, "INSERT INTO cp20_sql_t (name, qty) VALUES ('hack', 1)", false);
        $this->assertSame('blocked', $r['status']);
        // …and the table is provably untouched.
        $this->assertSame(2, DB::connection($this->conn())->table('cp20_sql_t')->count());
    }

    public function test_write_mode_insert_then_cleanup(): void
    {
        $r = SqlRunner::run($this->project, "INSERT INTO cp20_sql_t (name, qty) VALUES ('gamma', 9)", true);
        $this->assertSame('ok', $r['status']);
        $this->assertSame('write', $r['category']);
        $this->assertSame(1, $r['affected']);
        DB::connection($this->conn())->table('cp20_sql_t')->where('name', 'gamma')->delete();
    }

    public function test_cross_project_access_fails(): void
    {
        $r = SqlRunner::run($this->projectB, 'SELECT * FROM cp20_sql_t');
        $this->assertSame('error', $r['status']);
    }

    public function test_destructive_requires_confirmation_flag(): void
    {
        $r = SqlRunner::run($this->project, 'DELETE FROM cp20_sql_t', true);
        $this->assertSame('ok', $r['status']);
        $this->assertTrue($r['destructive']);
        // Roll back the test's own mess.
        DB::connection($this->conn())->table('cp20_sql_t')->insert([
            ['name' => 'alpha', 'qty' => 3], ['name' => 'beta', 'qty' => 7],
        ]);
    }
}
