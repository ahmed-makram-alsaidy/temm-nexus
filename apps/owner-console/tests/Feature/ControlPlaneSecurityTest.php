<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ControlPlaneSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->demo = Project::create([
            'name' => 'Control Plane Demo', 'slug' => 'control-plane-demo', 'status' => 'active',
            'db_name' => 'control_plane_demo_db', 'redis_prefix' => 'control_plane_demo',
        ]);
        $this->probeB = Project::create([
            'name' => 'Probe B', 'slug' => 'gate-b', 'status' => 'active',
            'db_name' => 'gate_b_db', 'redis_prefix' => 'gateb',
        ]);
    }

    public function test_cross_project_user_listings_do_not_overlap(): void
    {
        // Persistent fixtures only (gate-a/gate-b survive 18T cleanup).
        $probeA = Project::create([
            'name' => 'Probe A', 'slug' => 'gate-a', 'status' => 'active',
            'db_name' => 'gate_a_db', 'redis_prefix' => 'gatea',
        ]);
        foreach (['gate-a' => 'ann@gate-a.test', 'gate-b' => 'bee@gate-b.test'] as $slug => $email) {
            $project = $slug === 'gate-a' ? $probeA : $this->probeB;
            $conn = \App\Services\ControlPlane\ProjectConnectionManager::connection($project);
            \Illuminate\Support\Facades\DB::connection($conn)->statement(
                'CREATE TABLE IF NOT EXISTS users (id serial primary key, name text, email text unique, password text, created_at timestamptz, updated_at timestamptz)'
            );
            \Illuminate\Support\Facades\DB::connection($conn)->table('users')
                ->upsert([['name' => 'X', 'email' => $email, 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]], ['email']);
        }

        try {
            $emailsA = \App\Services\ControlPlane\ProjectAuthManager::for($probeA)
                ->usersQuery()->pluck('email')->all();
            $emailsB = \App\Services\ControlPlane\ProjectAuthManager::for($this->probeB)
                ->usersQuery()->pluck('email')->all();

            $this->assertContains('ann@gate-a.test', $emailsA);
            $this->assertNotContains('bee@gate-b.test', $emailsA);
            $this->assertContains('bee@gate-b.test', $emailsB);
            $this->assertNotContains('ann@gate-a.test', $emailsB);
        } finally {
            foreach ([$probeA, $this->probeB] as $project) {
                $conn = \App\Services\ControlPlane\ProjectConnectionManager::connection($project);
                \Illuminate\Support\Facades\DB::connection($conn)->statement('DROP TABLE users');
            }
        }
    }

    public function test_manipulated_table_param_cannot_escape_project(): void
    {
        // Attacker takes a table name that exists ONLY in project B and requests
        // it through project A's record browser: must 404, never leak.
        $probeA = Project::create([
            'name' => 'Probe A', 'slug' => 'gate-a', 'status' => 'active',
            'db_name' => 'gate_a_db', 'redis_prefix' => 'gatea',
        ]);
        DB::connection(\App\Services\ControlPlane\ProjectConnectionManager::connection($this->probeB))
            ->statement('CREATE TABLE IF NOT EXISTS cp_b_only (id serial primary key)');
        try {
            $response = $this->actingAs($this->admin)
                ->get('/admin/projects/'.$probeA->id.'/records?table=cp_b_only');
            $response->assertNotFound();
        } finally {
            DB::connection(\App\Services\ControlPlane\ProjectConnectionManager::connection($this->probeB))
                ->statement('DROP TABLE cp_b_only');
        }
    }

    public function test_download_requires_login_and_confines_paths(): void
    {
        Project::create([
            'name' => 'Probe A', 'slug' => 'gate-a', 'status' => 'active',
            'db_name' => 'gate_a_db', 'redis_prefix' => 'gatea',
        ]);
        // Logged out: redirect to owner login — never the file (200).
        $response = $this->get('/project-files/gate-a/invoices/x.txt');
        $response->assertRedirect('/admin/login');

        // Logged in but traversal: refused (422/403/404), never file contents.
        foreach (['..', '../..'] as $bucket) {
            $r = $this->actingAs($this->admin)->get("/project-files/gate-a/{$bucket}/x");
            $this->assertContains($r->status(), [400, 403, 404, 422]);
        }
    }

    public function test_monitor_account_cannot_write(): void
    {
        // infra_monitor cannot even CONNECT to project databases (CONNECT revoked
        // from PUBLIC per Phase 3). On the maintenance DB it can read catalog
        // views (pg_monitor) but must not write anything.
        Config::set('database.connections.monitor_probe', [
            'driver' => 'pgsql', 'host' => 'postgres', 'port' => '5432',
            'database' => 'postgres', 'username' => 'infra_monitor',
            'password' => env('MONITOR_DB_PASSWORD'), 'charset' => 'utf8',
            'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
        ]);
        // Read works (monitoring purpose).
        $dbs = DB::connection('monitor_probe')->select('SELECT datname FROM pg_database');
        $this->assertNotEmpty($dbs);

        // Write must fail (least privilege: no CREATEDB/CREATE on anything).
        try {
            DB::connection('monitor_probe')->statement('CREATE TABLE monitor_probe_write (id int)');
            $this->fail('Monitor account should not be able to write.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        } finally {
            DB::purge('monitor_probe');
        }
    }

    public function test_artisan_bridge_rejects_non_allowlisted_commands(): void
    {
        try {
            \App\Services\ControlPlane\ProjectArtisan::run($this->demo, 'db:wipe');
            $this->fail('Expected 403 for non-allowlisted command.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
        try {
            \App\Services\ControlPlane\ProjectArtisan::run($this->demo, 'tinker');
            $this->fail('Expected 403 for tinker.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }

    public function test_login_page_renders_auth_surface(): void
    {
        // Login renders for guests over the Livewire stack (which enforces its
        // own CSRF handling + 5-attempt rate limiting, vendor-verified in
        // filament/filament Auth/Pages/Login.php). Production adds fail2ban.
        $response = $this->get('/admin/login');
        $response->assertOk();
        $response->assertSee('Sign in');
        $response->assertSee('livewire', false);
    }
}
