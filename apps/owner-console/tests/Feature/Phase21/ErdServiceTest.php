<?php

namespace Tests\Feature\Phase21;

use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\DdlService;
use App\Services\ControlPlane\ErdService;
use App\Services\ControlPlane\ProjectConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 21A: ERD metadata is derived live from pg_catalog — FKs appear and
 * disappear automatically, isolation holds, system schemas stay hidden.
 * Disposable demo tables use the p21_ prefix and are dropped in tearDown.
 */
class ErdServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected Project $other;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->project = Project::create([
            'name' => 'Probe A', 'slug' => 'gate-a', 'status' => 'active',
            'db_name' => 'gate_a_db', 'redis_prefix' => 'gatea',
        ]);
        $this->other = Project::create([
            'name' => 'Probe B', 'slug' => 'gate-b', 'status' => 'active',
            'db_name' => 'gate_b_db', 'redis_prefix' => 'gateb',
        ]);
        $this->demoSchema();
    }

    protected function tearDown(): void
    {
        foreach (['p21' => $this->project, 'p21x' => $this->other] as $prefix => $project) {
            try {
                $conn = ProjectConnectionManager::connection($project);
                $names = array_column(
                    DB::connection($conn)->select(
                        "SELECT tablename AS name FROM pg_tables WHERE schemaname='public' AND tablename LIKE '{$prefix}%'"
                    ), 'name'
                );
                foreach ($names as $t) {
                    DB::connection($conn)->statement('DROP TABLE IF EXISTS "'.$t.'" CASCADE');
                }
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    /** Disposable demo shop: users 1:N orders 1:N order_items N:1 products, orders 1:N payments. */
    protected function demoSchema(): void
    {
        $conn = ProjectConnectionManager::connection($this->project);
        $db = fn (string $sql) => DB::connection($conn)->statement($sql);
        $db('DROP TABLE IF EXISTS p21_payments, p21_order_items, p21_orders, p21_products, p21_users CASCADE');
        $db('CREATE TABLE p21_users (id serial primary key, email text NOT NULL UNIQUE, name text)');
        $db('CREATE TABLE p21_products (id serial primary key, sku text NOT NULL UNIQUE, price numeric)');
        $db('CREATE TABLE p21_orders (id serial primary key, user_id integer NOT NULL REFERENCES p21_users(id), status text NOT NULL DEFAULT \'new\')');
        $db('CREATE TABLE p21_order_items (id serial primary key, order_id integer NOT NULL REFERENCES p21_orders(id) ON DELETE CASCADE, product_id integer NOT NULL REFERENCES p21_products(id), qty integer NOT NULL DEFAULT 1)');
        $db('CREATE TABLE p21_payments (id serial primary key, order_id integer NOT NULL UNIQUE REFERENCES p21_orders(id), amount numeric NOT NULL)');
    }

    public function test_demo_relationships_render_automatically(): void
    {
        $g = ErdService::graph($this->project);
        $names = array_column($g['tables'], 'name');
        foreach (['p21_users', 'p21_orders', 'p21_order_items', 'p21_products', 'p21_payments'] as $t) {
            $this->assertContains($t, $names);
        }
        $pairs = array_map(fn ($e) => $e['from_table'].'.'.$e['from_column'].'->'.$e['to_table'].'.'.$e['to_column'], $g['edges']);
        $this->assertContains('p21_orders.user_id->p21_users.id', $pairs);
        $this->assertContains('p21_order_items.order_id->p21_orders.id', $pairs);
        $this->assertContains('p21_order_items.product_id->p21_products.id', $pairs);
        $this->assertContains('p21_payments.order_id->p21_orders.id', $pairs);

        // Column detail: PK / FK / UNIQUE / nullable / type all present.
        $users = array_values(array_filter($g['tables'], fn ($t) => $t['name'] === 'p21_users'))[0];
        $byCol = array_column($users['columns'], null, 'name');
        $this->assertTrue($byCol['id']['pk']);
        $this->assertTrue($byCol['email']['unique']);
        $this->assertFalse($byCol['email']['nullable']);
        $this->assertTrue($byCol['name']['nullable']);
        $this->assertNotEmpty($byCol['email']['type']);

        $orders = array_values(array_filter($g['tables'], fn ($t) => $t['name'] === 'p21_orders'))[0];
        $this->assertSame(['id'], $orders['pk']);
        $this->assertArrayHasKey('metadata_ms', $g['timings']);
    }

    public function test_fk_appears_and_disappears_without_erd_changes(): void
    {
        // New FK via the existing Schema tooling…
        DdlService::addForeignKey($this->project, 'p21_products', 'id', 'p21_users', 'id');
        $pairs = array_map(fn ($e) => $e['from_table'].'.'.$e['from_column'].'->'.$e['to_table'].'.'.$e['to_column'],
            ErdService::graph($this->project)['edges']);
        $this->assertContains('p21_products.id->p21_users.id', $pairs);

        // …then removed again; the ERD follows with no other change.
        DdlService::dropForeignKey($this->project, 'p21_products', 'fk_p21_products_id');
        $pairs = array_map(fn ($e) => $e['from_table'].'.'.$e['from_column'].'->'.$e['to_table'].'.'.$e['to_column'],
            ErdService::graph($this->project)['edges']);
        $this->assertNotContains('p21_products.id->p21_users.id', $pairs);
        // The pre-existing edges survived the round-trip.
        $this->assertContains('p21_orders.user_id->p21_users.id', $pairs);
    }

    public function test_project_isolation(): void
    {
        $connB = ProjectConnectionManager::connection($this->other);
        DB::connection($connB)->statement('DROP TABLE IF EXISTS p21x_secret CASCADE');
        DB::connection($connB)->statement('CREATE TABLE p21x_secret (id serial primary key)');

        $namesA = array_column(ErdService::graph($this->project)['tables'], 'name');
        $this->assertNotContains('p21x_secret', $namesA);
        $this->assertContains('p21x_secret', array_column(ErdService::graph($this->other)['tables'], 'name'));
    }

    public function test_system_schemas_hidden(): void
    {
        $this->assertNotContains('pg_catalog', ErdService::schemas($this->project));
        $this->assertNotContains('information_schema', ErdService::schemas($this->project));

        try {
            ErdService::graph($this->project, 'pg_catalog');
            $this->fail('Expected 403 for system schema.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_synthetic_100_table_schema_stays_usable(): void
    {
        $conn = ProjectConnectionManager::connection($this->project);
        DB::connection($conn)->statement('DROP SCHEMA IF EXISTS p21big CASCADE');
        DB::connection($conn)->statement('CREATE SCHEMA p21big');
        for ($i = 1; $i <= 100; $i++) {
            $prev = $i === 1 ? null : 'p21big_t_'.str_pad((string) ($i - 1), 3, '0', STR_PAD_LEFT);
            $name = 'p21big_t_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            DB::connection($conn)->statement(
                'CREATE TABLE p21big.'.$name.' (id serial primary key, label text, ref_id integer'
                .($prev ? ' REFERENCES p21big.'.$prev.'(id)' : '').')'
            );
        }
        try {
            $started = microtime(true);
            $g = ErdService::graph($this->project, 'p21big');
            $ms = (int) ((microtime(true) - $started) * 1000);
            $this->assertCount(100, $g['tables']);
            $this->assertCount(99, $g['edges']);
            // Honest timing assertion: metadata for 100 tables must complete
            // well within an interactive budget (recorded in the Phase 21 report).
            $this->assertLessThan(15000, $ms, "100-table metadata took {$ms}ms");
            fwrite(STDERR, "\n[phase21] 100-table ERD metadata: {$ms}ms (server), tables=100 edges=99\n");
        } finally {
            DB::connection($conn)->statement('DROP SCHEMA IF EXISTS p21big CASCADE');
        }
    }

    public function test_erd_http_api_and_layout_persistence(): void
    {
        $show = $this->actingAs($this->admin)->getJson('/cp-erd/'.$this->project->id.'?schema=public');
        $show->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('graph.schema', 'public');
        $this->assertNotEmpty($show->json('graph.tables'));

        $this->actingAs($this->admin)->getJson('/cp-erd/'.$this->project->id.'/schemas')
            ->assertOk()->assertJsonFragment(['public']);

        $layout = ['p21_users' => ['x' => 10, 'y' => 20, 'collapsed' => false]];
        $this->actingAs($this->admin)->putJson('/cp-erd/'.$this->project->id.'/layout', [
            'schema' => 'public', 'layout' => $layout,
        ])->assertOk();

        $again = $this->actingAs($this->admin)->getJson('/cp-erd/'.$this->project->id.'?schema=public');
        $this->assertSame(10, (int) $again->json('layout.p21_users.x'));

        // A user with no grants gets 403 (guest redirect covered separately).
        $nobody = User::factory()->create(['is_admin' => false, 'cp_role' => null]);
        $this->actingAs($nobody)->getJson('/cp-erd/'.$this->project->id)->assertForbidden();
    }

    public function test_erd_api_requires_auth(): void
    {
        // Fresh app, no actingAs: JSON guest gets 401 (never schema data).
        $this->getJson('/cp-erd/'.$this->project->id)->assertUnauthorized();
    }

    public function test_erd_and_infra_pages_render(): void
    {
        $this->actingAs($this->admin)->get('/admin/projects/'.$this->project->id.'/erd')->assertOk();
        $this->actingAs($this->admin)->get('/admin/projects/'.$this->project->id.'/infrastructure')->assertOk();
        $this->actingAs($this->admin)->get('/admin/infra-nodes')->assertOk();
        $this->actingAs($this->admin)->get('/admin/infra-services')->assertOk();
        $this->actingAs($this->admin)->get('/admin/infra-health')->assertOk();
        $this->actingAs($this->admin)->get('/admin/infra-topology')->assertOk();
        $this->actingAs($this->admin)
            ->get('/admin/infra-node?node=node-local-01')->assertOk();
    }
}
