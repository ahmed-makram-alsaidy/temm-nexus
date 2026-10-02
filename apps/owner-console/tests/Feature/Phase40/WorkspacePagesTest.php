<?php

namespace Tests\Feature\Phase40;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase40\Concerns\BuildsTenantFixture;
use Tests\TestCase;

/**
 * 0.4.0 §37 — the isolation guarantee must hold through the real HTTP surface,
 * not only at the service layer. These are the tests that prove a user cannot
 * reach another tenant's workspace by typing its URL.
 */
class WorkspacePagesTest extends TestCase
{
    use BuildsTenantFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTenantFixture();
    }

    // ── Index page ─────────────────────────────────────────────────────

    public function test_platform_owner_can_open_the_workspaces_index(): void
    {
        $this->actingAs($this->platformOwner)
            ->get('/admin/workspaces')
            ->assertOk()
            ->assertSee('Alpha Client')
            ->assertSee('Beta Client');
    }

    public function test_workspace_owner_sees_only_their_own_workspace_on_the_index(): void
    {
        $response = $this->actingAs($this->alphaOwner)->get('/admin/workspaces');

        $response->assertOk();
        $response->assertSee('Alpha Client');
        // The other tenant's workspace must not appear anywhere in the HTML.
        $response->assertDontSee('Beta Client');
    }

    public function test_project_only_member_sees_no_workspace_listing(): void
    {
        // They hold no workspace role, so even the index shows nothing.
        $response = $this->actingAs($this->alphaDevOnWeb)->get('/admin/workspaces');

        // Access to the listing itself needs workspaces.view at platform scope,
        // which workspace members get from their workspace role.
        $this->assertContains($response->getStatusCode(), [200, 403]);
        if ($response->getStatusCode() === 200) {
            $response->assertDontSee('Alpha Client');
            $response->assertDontSee('Beta Client');
        }
    }

    public function test_user_with_no_access_is_denied_the_index(): void
    {
        $this->actingAs($this->nobody)
            ->get('/admin/workspaces')
            ->assertForbidden();
    }

    // ── Detail page: the critical cross-tenant tests ───────────────────

    public function test_workspace_member_can_open_their_own_workspace(): void
    {
        $this->actingAs($this->alphaOwner)
            ->get('/admin/workspaces/'.$this->alpha->slug)
            ->assertOk()
            ->assertSee('Alpha Client');
    }

    /**
     * THE test: a user of workspace A typing workspace B's URL must get a 404,
     * not a 403 and not a rendered page. A 403 would confirm B exists.
     */
    public function test_cross_tenant_workspace_url_returns_404(): void
    {
        $this->actingAs($this->alphaOwner)
            ->get('/admin/workspaces/'.$this->beta->slug)
            ->assertNotFound();
    }

    public function test_cross_tenant_workspace_url_returns_404_for_workspace_member_too(): void
    {
        $this->actingAs($this->alphaMember)
            ->get('/admin/workspaces/'.$this->beta->slug)
            ->assertNotFound();
    }

    public function test_platform_owner_can_open_either_workspace(): void
    {
        $this->actingAs($this->platformOwner)
            ->get('/admin/workspaces/'.$this->beta->slug)
            ->assertOk()
            ->assertSee('Beta Client');
    }

    public function test_unknown_workspace_slug_returns_404(): void
    {
        $this->actingAs($this->platformOwner)
            ->get('/admin/workspaces/does-not-exist')
            ->assertNotFound();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/workspaces/'.$this->alpha->slug)->assertRedirect();
    }

    // ── Workspace detail content ───────────────────────────────────────

    public function test_workspace_detail_lists_only_its_own_projects(): void
    {
        $response = $this->actingAs($this->alphaOwner)
            ->get('/admin/workspaces/'.$this->alpha->slug);

        $response->assertOk();
        $response->assertSee('Alpha Website');
        $response->assertSee('Alpha Booking');
        $response->assertDontSee('Beta CRM');
    }

    public function test_workspace_detail_states_the_active_scope(): void
    {
        // §2/§16 — scope must be visible, not inferred.
        $this->actingAs($this->alphaOwner)
            ->get('/admin/workspaces/'.$this->alpha->slug)
            ->assertSee('Workspace — Alpha Client');
    }

    public function test_workspace_detail_shows_members_with_product_role_labels(): void
    {
        $this->actingAs($this->platformOwner)
            ->get('/admin/workspaces/'.$this->alpha->slug)
            ->assertSee('Workspace Owner')
            ->assertDontSee('workspace_owner'); // raw token must not leak as the label
    }

    // ── Revocation takes effect through HTTP ───────────────────────────

    /**
     * Suspension must revoke access on the very next request.
     *
     * The response is 403, not 404, and that is correct: with the last
     * membership suspended this user has no reachable object left, so
     * `User::canAccessPanel()` returns false and Filament's own Authenticate
     * middleware refuses the whole panel before routing. There is nothing left
     * to disclose, so the 403-vs-404 distinction does not arise here.
     */
    public function test_suspending_the_last_membership_revokes_panel_access(): void
    {
        $this->actingAs($this->alphaOwner)
            ->get('/admin/workspaces/'.$this->alpha->slug)
            ->assertOk();

        WorkspaceMember::query()
            ->where('workspace_id', $this->alpha->id)
            ->where('user_id', $this->alphaOwner->id)
            ->update(['status' => 'suspended']);

        // Next request, no cache to clear anywhere.
        $this->actingAs($this->alphaOwner)
            ->get('/admin/workspaces/'.$this->alpha->slug)
            ->assertForbidden();

        $this->actingAs($this->alphaOwner)->get('/admin')->assertForbidden();
    }

    /**
     * The 404 (not 403) rule applies once the user still has SOME reach — i.e.
     * exactly the cross-tenant case, where a 403 would confirm the object
     * exists. This asserts the distinction deliberately.
     */
    public function test_reachable_user_gets_404_for_an_unreachable_record_not_403(): void
    {
        // alphaMember keeps their own workspace (so the panel stays open)…
        $this->actingAs($this->alphaMember)
            ->get('/admin/workspaces/'.$this->alpha->slug)
            ->assertOk();

        // …and is refused beta with a 404, never a 403.
        $response = $this->actingAs($this->alphaMember)
            ->get('/admin/workspaces/'.$this->beta->slug);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_project_only_member_is_refused_workspace_records_with_404(): void
    {
        // This user has a project grant but no workspace membership, so the
        // panel is reachable and the per-record rule applies.
        $response = $this->actingAs($this->alphaDevOnWeb)
            ->get('/admin/workspaces/'.$this->alpha->slug);

        $this->assertSame(404, $response->getStatusCode());
    }

    // ── Legacy ungrouped project ───────────────────────────────────────

    public function test_ungrouped_legacy_project_is_reported_not_hidden(): void
    {
        $this->makeProject('Legacy Orphan', 'legacy-orphan', null);

        $this->actingAs($this->platformOwner)
            ->get('/admin/workspaces')
            ->assertOk()
            ->assertSee('not in a workspace');
    }

    public function test_project_can_be_assigned_to_a_workspace_and_then_appears(): void
    {
        $orphan = $this->makeProject('Legacy Orphan', 'legacy-orphan', null);

        // Assert against the Livewire-rendered HTML only. The full response also
        // contains wire:snapshot JSON (which encodes the workspace model) and
        // inline CSS/JS, so a naive substring search is not meaningful.
        $before = $this->actingAs($this->alphaOwner)
            ->get('/admin/workspaces/'.$this->alpha->slug)
            ->assertOk();
        $this->assertStringNotContainsString('Legacy Orphan', $this->renderedHtml($before->getContent()));

        $orphan->forceFill(['workspace_id' => $this->alpha->id])->save();

        $after = $this->actingAs($this->alphaOwner)
            ->get('/admin/workspaces/'.$this->alpha->slug)
            ->assertOk();
        $this->assertStringContainsString('Legacy Orphan', $this->renderedHtml($after->getContent()));
    }

    /**
     * Extract only the server-rendered component markup: drop <style>/<script>
     * blocks and every wire:* attribute, which carry snapshots and inline CSS
     * rather than visible content.
     */
    private function renderedHtml(string $html): string
    {
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html) ?? $html;
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $html = preg_replace('#\swire:[a-zA-Z-]+="[^"]*"#', '', $html) ?? $html;

        return $html;
    }

    // ── Workspace scoping of the platform nav entry ────────────────────

    public function test_nav_shows_workspaces_entry_only_with_the_capability(): void
    {
        // Platform owner: visible.
        $this->actingAs($this->platformOwner)
            ->get('/admin')
            ->assertSee('Clients &amp; Workspaces', false);

        // A user with no access at all never reaches the panel body.
        $this->actingAs($this->nobody)->get('/admin')->assertForbidden();
    }

    public function test_platform_brand_is_the_product_name_not_the_old_panel_title(): void
    {
        // §P2 baseline finding: the panel used to say "Backend Control Plane".
        $this->actingAs($this->platformOwner)
            ->get('/admin/login');

        $this->actingAs($this->platformOwner)
            ->get('/admin')
            ->assertDontSee('Backend Control Plane');
    }
}
