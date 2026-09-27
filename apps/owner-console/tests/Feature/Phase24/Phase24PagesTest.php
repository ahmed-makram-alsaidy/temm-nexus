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

    public function test_subnav_exposes_phase24_modules(): void
    {
        $url = \App\Filament\Resources\Projects\ProjectResource::getUrl('overview', ['record' => $this->projectA]);
        $content = (string) $this->actingAs($this->admin)->get($url)->getContent();

        $this->assertStringContainsString('migration-center', $content);
        $this->assertStringContainsString('schema-diff', $content);
        $this->assertStringContainsString('readiness', $content);
        $this->assertStringContainsString('environments', $content);
        $this->assertStringContainsString('Environment', $content, 'environment switcher visible in workspace');
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
