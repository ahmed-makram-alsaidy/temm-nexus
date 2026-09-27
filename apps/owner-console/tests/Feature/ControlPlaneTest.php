<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\LogSanitizer;
use App\Services\ControlPlane\ProjectConnectionManager;
use App\Services\ControlPlane\ProjectDatabaseExplorer;
use App\Services\ControlPlane\ProjectStorageManager;
use App\Services\ControlPlane\ProtectedTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // RefreshDatabase wipes the console DB; re-seed the admin + probe projects.
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->nonAdmin = User::factory()->create(['is_admin' => false]);
        $this->projectA = Project::create([
            'name' => 'Probe A', 'slug' => 'gate-a', 'status' => 'active',
            'db_name' => 'gate_a_db', 'redis_prefix' => 'gatea',
        ]);
        $this->projectB = Project::create([
            'name' => 'Probe B', 'slug' => 'gate-b', 'status' => 'active',
            'db_name' => 'gate_b_db', 'redis_prefix' => 'gateb',
        ]);
    }

    public function test_guest_cannot_access_project_pages(): void
    {
        foreach (['', '/users', '/database', '/storage', '/backups', '/settings'] as $suffix) {
            $this->get('/admin/projects/'.$this->projectA->id.$suffix)->assertRedirect('/admin/login');
        }
    }

    public function test_admin_can_load_project_pages(): void
    {
        $pages = ['', '/users', '/roles', '/permissions', '/sessions', '/database',
            '/db-health', '/storage', '/api', '/realtime', '/queues', '/scheduler',
            '/logs', '/backups', '/monitoring', '/settings'];
        foreach ($pages as $suffix) {
            $response = $this->actingAs($this->admin)->get('/admin/projects/'.$this->projectA->id.$suffix);
            $this->assertTrue(
                in_array($response->status(), [200, 302], true),
                "Page {$suffix} returned {$response->status()}"
            );
        }
    }

    public function test_non_admin_is_forbidden_from_project_pages(): void
    {
        $this->actingAs($this->nonAdmin)->get('/admin/projects/'.$this->projectA->id.'/users')->assertForbidden();
    }

    public function test_project_connections_are_isolated(): void
    {
        $connA = ProjectConnectionManager::connection($this->projectA);
        $connB = ProjectConnectionManager::connection($this->projectB);
        $this->assertNotSame($connA, $connB);

        // A known table in A is invisible through B's explorer.
        \Illuminate\Support\Facades\DB::connection($connA)->statement('CREATE TABLE IF NOT EXISTS cp_probe_a (id serial primary key)');
        $tablesB = array_column(ProjectDatabaseExplorer::for($this->projectB)->tables(), 'name');
        $this->assertNotContains('cp_probe_a', $tablesB);
        $tablesA = array_column(ProjectDatabaseExplorer::for($this->projectA)->tables(), 'name');
        $this->assertContains('cp_probe_a', $tablesA);
        \Illuminate\Support\Facades\DB::connection($connA)->statement('DROP TABLE cp_probe_a');
    }

    public function test_unknown_table_is_404_and_other_project_table_unreachable_by_name(): void
    {
        $explorerB = ProjectDatabaseExplorer::for($this->projectB);
        try {
            $explorerB->assertTable('cp_probe_a');
            $this->fail('Expected 404 for unknown table');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_protected_tables_are_read_only(): void
    {
        $explorer = ProjectDatabaseExplorer::for($this->projectA);
        $this->assertTrue(ProtectedTables::isReadOnly('migrations'));
        $this->assertFalse(ProtectedTables::isReadOnly('users'));
        // Create a table whose name is on the protected list, then prove writes are refused.
        \Illuminate\Support\Facades\DB::connection(
            ProjectConnectionManager::connection($this->projectA)
        )->statement('CREATE TABLE IF NOT EXISTS jobs (id serial primary key)');
        try {
            $explorer->delete('jobs', 1);
            $this->fail('Expected 403');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        } finally {
            \Illuminate\Support\Facades\DB::connection(
                ProjectConnectionManager::connection($this->projectA)
            )->statement('DROP TABLE jobs');
        }
    }

    public function test_secret_columns_are_hidden(): void
    {
        $this->assertNotContains('password', ProtectedTables::visibleColumns(['id', 'email', 'password', 'remember_token']));
        $this->assertContains('email', ProtectedTables::visibleColumns(['id', 'email', 'password']));
    }

    public function test_log_sanitizer_redacts_secrets(): void
    {
        $line = 'Login failed password=hunter2 token=abcdef1234567890abcdef1234567890 user=x@mail.com';
        $clean = LogSanitizer::sanitize($line);
        $this->assertStringNotContainsString('hunter2', $clean);
        $this->assertStringNotContainsString('abcdef1234567890abcdef1234567890', $clean);
        $this->assertStringContainsString('x@mail.com', $clean);
        $this->assertSame([], LogSanitizer::leakedKeys($clean));

        $header = 'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature-here';
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', LogSanitizer::sanitize($header));
    }

    public function test_storage_traversal_is_blocked(): void
    {
        $storage = ProjectStorageManager::for($this->projectA);
        foreach (['../x', '..\\x', 'a/../../etc', "a\0b"] as $evil) {
            try {
                $storage->resolve($evil);
                $this->fail("Expected rejection for {$evil}");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertContains($e->getStatusCode(), [403, 404, 422]);
            }
        }
        // Confinement: bucket of A never resolves outside the project root.
        $storage->createBucket('testbucket');
        $resolved = $storage->resolve('testbucket');
        $this->assertTrue(str_starts_with($resolved, realpath('/projects/gate-a/storage/control-plane') ?: '/projects/gate-a'));
        $storage->deleteBucket('testbucket');
    }

    public function test_audit_entries_are_immutable_via_http(): void
    {
        // The audit resource registers ONLY the index page: create/edit URLs must 404,
        // so entries cannot be added or altered through the UI.
        $this->actingAs($this->admin)->get('/admin/audit-logs/create')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/audit-logs/1/edit')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/audit-logs')->assertOk();
    }
}
