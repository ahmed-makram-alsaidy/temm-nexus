<?php

namespace Tests\Feature\Phase24;

use App\Models\MigrationSource;
use App\Services\ControlPlane\EnvironmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase24\Concerns\BuildsEngineFixture;
use Tests\TestCase;

/**
 * Phase 24 pages over HTTP: rendering, project isolation (the {record}
 * binding is the only project selector), and RBAC gating of manage actions.
 */
class Phase24PagesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEngineFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBase();
        EnvironmentService::ensureDefaults($this->projectA);
        EnvironmentService::ensureDefaults($this->projectB);
    }

    public function test_all_phase24_pages_render_for_admin(): void
    {
        $pages = ['environments', 'migration-center', 'schema-diff', 'resources', 'readiness', 'connect', 'backups', 'secrets'];
        foreach ($pages as $page) {
            $url = \App\Filament\Resources\Projects\ProjectResource::getUrl($page, ['record' => $this->projectA]);
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    public function test_onboarding_page_renders(): void
    {
        $this->actingAs($this->admin)->get('/admin/onboarding')->assertOk();
    }

    public function test_migration_center_isolates_projects(): void
    {
        MigrationSource::create([
            'project_id' => $this->projectA->id, 'type' => 'sqlite', 'display_name' => 'SOURCE-ONLY-IN-A',
            'connection' => ['path' => '/tmp/irrelevant.sqlite'], 'read_only' => true, 'status' => 'pending',
        ]);

        $urlB = \App\Filament\Resources\Projects\ProjectResource::getUrl('migration-center', ['record' => $this->projectB]);
        $response = $this->actingAs($this->admin)->get($urlB);
        $response->assertOk();
        $this->assertStringNotContainsString('SOURCE-ONLY-IN-A', $response->getContent(), 'project B page must never show project A sources');

        $urlA = \App\Filament\Resources\Projects\ProjectResource::getUrl('migration-center', ['record' => $this->projectA]);
        $this->actingAs($this->admin)->get($urlA)->assertOk()->assertSee('SOURCE-ONLY-IN-A');
    }

    public function test_unauthenticated_users_are_redirected(): void
    {
        $url = \App\Filament\Resources\Projects\ProjectResource::getUrl('readiness', ['record' => $this->projectA]);
        $this->get($url)->assertRedirect();
    }

    /**
     * 0.6.0 Phase B — the 35-link project sidebar became the context tab bar.
     * The Phase 24 modules are still every-bit reachable: the Migration tab
     * carries the migration modules, the Operate tab carries readiness and
     * environments, and the environment switcher lives in the tab row.
     */
    public function test_subnav_exposes_phase24_modules(): void
    {
        $acting = $this->actingAs($this->admin);

        // The project shell renders the seven primary tabs everywhere.
        $overview = (string) $acting->get(
            \App\Filament\Resources\Projects\ProjectResource::getUrl('overview', ['record' => $this->projectA])
        )->getContent();
        foreach (['Overview', 'Migration', 'Data', 'Access', 'Build', 'Operate', 'Settings'] as $tab) {
            $this->assertStringContainsString('>'.$tab.'<', $overview, "Primary tab {$tab} missing from the project shell");
        }
        $this->assertStringContainsString(
            __('nav.manage_environments'),
            $overview,
            'environment switcher visible in the project tab row',
        );

        // Migration tab exposes the migration modules.
        $migration = (string) $acting->get(
            \App\Filament\Resources\Projects\ProjectResource::getUrl('migration-center', ['record' => $this->projectA])
        )->getContent();
        $this->assertStringContainsString('migration-center', $migration);
        $this->assertStringContainsString('schema-diff', $migration);

        // Operate tab exposes readiness and environments.
        $operate = (string) $acting->get(
            \App\Filament\Resources\Projects\ProjectResource::getUrl('readiness', ['record' => $this->projectA])
        )->getContent();
        $this->assertStringContainsString('readiness', $operate);
        $this->assertStringContainsString('environments', $operate);
    }

    public function test_observer_has_read_only_access_to_phase24_pages(): void
    {
        $observer = \App\Models\User::factory()->create(['is_admin' => false, 'cp_role' => 'observer']);

        // Observer can VIEW (projects.view), but every manage permission is denied.
        $url = \App\Filament\Resources\Projects\ProjectResource::getUrl('environments', ['record' => $this->projectA]);
        $this->actingAs($observer)->get($url)->assertOk();

        foreach (['environments.manage', 'migrations.manage', 'backups.policy', 'readiness.acknowledge'] as $permission) {
            $this->assertFalse(\App\Services\ControlPlane\CpAccess::allows($observer, $permission), "observer must not hold {$permission}");
        }
        $this->assertTrue(\App\Services\ControlPlane\CpAccess::allows($this->admin, 'migrations.manage'));
        $this->assertTrue(\App\Services\ControlPlane\CpAccess::allows($this->admin, 'backups.policy'));
    }
}
