<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\ProjectDatabaseExplorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Concerns\RequiresDemoDatabase;

class RecordBrowserTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDemoDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDemoDatabase();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->demo = Project::create([
            'name' => 'Control Plane Demo', 'slug' => 'control-plane-demo', 'status' => 'active',
            'db_name' => 'control_plane_demo_db', 'redis_prefix' => 'control_plane_demo',
        ]);
    }

    public function test_records_page_renders_table_data(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/projects/'.$this->demo->id.'/records?table=products');
        $response->assertOk();
        // Livewire renders the table server-side; seeded SKU must be present.
        $response->assertSee('SKU-001');
    }

    public function test_records_page_paginates_large_table(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/projects/'.$this->demo->id.'/records?table=demo_big_rows');
        $response->assertOk();
        // 5000 rows must NOT all render: paginator shows a page subset.
        $this->assertLessThan(
            5000,
            substr_count($response->getContent(), 'row-'),
            'Large table appears fully rendered — pagination broken.'
        );
        $this->assertGreaterThan(0, substr_count($response->getContent(), 'row-'));
    }

    public function test_explorer_crud_round_trip(): void
    {
        $explorer = ProjectDatabaseExplorer::for($this->demo);

        $id = $explorer->insert('products', [
            'sku' => 'CP-TEST-1', 'name' => 'Probe', 'price' => 9.99,
            'stock' => 3, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNotEmpty($id);

        $found = $explorer->find('products', $id);
        $this->assertEquals('CP-TEST-1', $found->sku);

        $explorer->update('products', $id, ['stock' => 7]);
        $this->assertEquals(7, $explorer->find('products', $id)->stock);

        // Search path used by the browser: filter + sort + paginate via query builder.
        $hits = $explorer->query('products')->where('sku', 'like', '%CP-TEST%')->orderBy('id')->paginate(15);
        $this->assertEquals(1, $hits->total());

        $explorer->delete('products', $id);
        $this->assertNull($explorer->find('products', $id));
    }

    public function test_schema_metadata_reports_relationships(): void
    {
        $explorer = ProjectDatabaseExplorer::for($this->demo);

        $fks = $explorer->foreignKeys('order_items');
        $targets = array_map(fn ($fk) => $fk['to_table'].'.'.$fk['to_column'], $fks);
        $this->assertContains('orders.id', $targets);
        $this->assertContains('products.id', $targets);

        $cols = array_column($explorer->columns('products'), 'name');
        foreach (['sku', 'price', 'stock', 'is_active', 'attributes'] as $expected) {
            $this->assertContains($expected, $cols);
        }

        $response = $this->actingAs($this->admin)
            ->get('/admin/projects/'.$this->demo->id.'/schema?table=order_items');
        $response->assertOk();
        $response->assertSee('orders.id');
    }

    public function test_password_hashes_never_rendered(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/projects/'.$this->demo->id.'/records?table=users');
        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringNotContainsString('$2y$', $content);
        $this->assertStringNotContainsString('demo-pass-123', $content);
        $response->assertSee('customer@demo.test');
    }
}
