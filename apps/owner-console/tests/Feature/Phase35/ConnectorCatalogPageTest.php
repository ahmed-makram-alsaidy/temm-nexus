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
 *
 * 0.4.0 Phase F note: the catalogue moved from the internal path
 * `/admin/connector-catalog` to the product path `/admin/connectors`, and the
 * old URL now issues a 302 redirect so existing bookmarks keep working. This
 * test still guards the SAME thing it always did — that the page renders
 * without erroring — and additionally pins the redirect, so a future change
 * that drops either one fails here rather than silently breaking a bookmark.
 */
class ConnectorCatalogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_connector_catalog_page_renders_for_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get('/admin/connectors')
            ->assertOk()
            ->assertSee('Connectors', false)
            // Real catalogue content, not an error page.
            ->assertSee('Everything you can migrate from', false);
    }

    public function test_the_legacy_catalog_url_redirects_to_the_new_one(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get('/admin/connector-catalog')
            ->assertRedirect('/admin/connectors');

        // Following the redirect must land on a working page.
        $this->actingAs($admin)
            ->followingRedirects()
            ->get('/admin/connector-catalog')
            ->assertOk()
            ->assertSee('Connectors', false);
    }
}
