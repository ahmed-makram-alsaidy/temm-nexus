<?php

namespace Tests\Feature\Phase35;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 36.5 regression — the Connector Catalog page must render for an
 * admin. On rc.2 it 500'd for every user: Table::columns() received a
 * Closure where this Filament version requires an array (found live on
 * the Azure soak via the real browser).
 */
class ConnectorCatalogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_connector_catalog_page_renders_for_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get('/admin/connector-catalog')
            ->assertOk()
            ->assertSee('Connector Catalog', false);
    }
}
