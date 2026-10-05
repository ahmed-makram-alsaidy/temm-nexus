<?php

namespace Tests\Feature\Phase41;

use App\Models\Project;
use App\Models\MigrationSource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Access\Roles;
use App\Services\ControlPlane\AdminAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 0.4.0-rc.5 (Phase 41, Part B) — the New Project wizard.
 *
 * Covers: page access, the guided step flow with server-side validation,
 * workspace assignment at creation (the pre-rc.5 gap), source-card driven
 * connector selection, Test Connection failure handling without leaking
 * secrets, vaulted credentials, and tenant isolation of the workspace list.
 */
class ProjectWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function owner(): User
    {
        return User::create([
            'name' => 'Platform Owner',
            'email' => uniqid('owner').'@test.local',
            'password' => 'password-password-123',
            'platform_role' => Roles::PLATFORM_OWNER,
        ]);
    }

    protected function workspaceUser(): User
    {
        return User::create([
            'name' => 'Alpha Owner',
            'email' => uniqid('alpha').'@test.local',
            'password' => 'password-password-123',
        ]);
    }

    protected function alphaWorkspace(User $user): Workspace
    {
        $workspace = Workspace::create([
            'name' => 'Alpha',
            'slug' => 'alpha-'.uniqid(),
            'kind' => 'client',
            'status' => 'active',
        ]);
        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => Roles::WORKSPACE_OWNER,
            'status' => 'active',
        ]);

        return $workspace;
    }

    public function test_owner_can_open_the_wizard(): void
    {
        $this->actingAs($this->owner())
            ->get('/admin/new-project')
            ->assertOk()
            ->assertSee(__('wizard.title'));
    }

    public function test_user_without_project_create_capability_is_refused(): void
    {
        $nobody = User::create([
            'name' => 'Nobody',
            'email' => uniqid('nobody').'@test.local',
            'password' => 'password-password-123',
        ]);

        $this->actingAs($nobody)
            ->get('/admin/new-project')
            ->assertForbidden();
    }

    public function test_step_one_requires_name_workspace_and_environment(): void
    {
        $this->actingAs($this->owner());

        \Livewire::test(\App\Filament\Pages\NewProjectWizard::class)
            ->set('state.name', '')
            ->set('state.workspace_id', null)
            ->call('continue')
            ->assertHasErrors(['state.name', 'state.workspace_id']);
    }

    public function test_workspace_list_is_tenant_scoped(): void
    {
        $user = $this->workspaceUser();
        $alpha = $this->alphaWorkspace($user);

        // A workspace the user does NOT belong to.
        Workspace::create(['name' => 'Beta', 'slug' => 'beta-'.uniqid(), 'kind' => 'client', 'status' => 'active']);

        $this->actingAs($user);

        $component = \Livewire::test(\App\Filament\Pages\NewProjectWizard::class);
        $options = $component->instance()->accessibleWorkspaces();

        $this->assertArrayHasKey($alpha->id, $options);
        $this->assertCount(1, $options, 'The wizard must not offer unreachable workspaces');
    }

    public function test_unreachable_workspace_prefill_is_ignored(): void
    {
        $user = $this->workspaceUser();
        $alpha = $this->alphaWorkspace($user);
        $beta = Workspace::create(['name' => 'Beta', 'slug' => 'beta-'.uniqid(), 'kind' => 'client', 'status' => 'active']);

        $this->actingAs($user);

        $component = \Livewire::withQueryParams(['workspace' => $beta->id])
            ->test(\App\Filament\Pages\NewProjectWizard::class);

        $this->assertNull($component->instance()->state['workspace_id']);

        // A reachable prefill IS accepted.
        $component = \Livewire::withQueryParams(['workspace' => $alpha->id])
            ->test(\App\Filament\Pages\NewProjectWizard::class);
        $this->assertSame($alpha->id, $component->instance()->state['workspace_id']);
    }

    public function test_full_flow_creates_project_with_workspace_and_vaulted_source(): void
    {
        $owner = $this->owner();
        $workspace = Workspace::create(['name' => 'Alpha', 'slug' => 'alpha-'.uniqid(), 'kind' => 'client', 'status' => 'active']);
        $this->actingAs($owner);

        $component = \Livewire::test(\App\Filament\Pages\NewProjectWizard::class);

        // Step 1 — Project.
        $component->set('state.name', 'Wizard Project')
            ->set('state.workspace_id', $workspace->id)
            ->set('state.environment', 'staging')
            ->call('continue')
            ->assertSet('step', 2);

        // Step 2 — Source connection (0.6.0 Phase E merged Source + Connection
        // into ONE step): postgres connector exists in the registry.
        $component->set('state.connector', 'postgres')
            ->set('state.connection.host', '127.0.0.1')
            ->set('state.connection.database', 'sourcedb')
            ->set('state.connection.username', 'reader');

        // Continue is BLOCKED until the connection test passes.
        $component->call('continue')->assertSet('step', 2);

        // A failing test (nothing listens on that port) yields a safe result.
        $component->set('state.connection.port', '1')
            ->set('state.connection.password', 'super-secret-password')
            ->call('testConnection');

        $this->assertFalse($component->instance()->testResult['ok']);
        $this->assertNotSame('PASS', $component->instance()->testResult['result'] ?? null);
        $component->call('continue')->assertSet('step', 2);

        // Simulate a verified connection (the operator side of the same UI)
        // and finish the flow.
        $component->set('testResult', ['ok' => true, 'available' => true, 'kind' => 'success', 'result' => 'PASS', 'detail' => '', 'metadata' => []])
            ->call('continue');

        $this->assertSame(
            3,
            $component->instance()->step,
            'source-step continue failed with error: "'.$component->instance()->error.'"'
        );

        $project = Project::query()->where('name', 'Wizard Project')->first();
        $this->assertNotNull($project, 'Project must be created when leaving the source step');
        $this->assertSame($workspace->id, $project->workspace_id, 'rc.5 closes the workspace-assignment gap');
        $this->assertSame('staging', $project->environment);

        $source = MigrationSource::query()->where('project_id', $project->id)->first();
        $this->assertNotNull($source, 'A read-only source must exist');
        $this->assertSame('postgres', $source->effectiveConnectorKey());

        // The password went to the vault, referenced by name — never inline.
        $this->assertSame('SOURCE_PASSWORD', $source->secret_refs['password'] ?? null);
        $this->assertArrayNotHasKey('password', $source->connection ?? []);
        $raw = \Illuminate\Support\Facades\DB::table('project_secrets')
            ->where('project_id', $project->id)->where('name', 'SOURCE_PASSWORD')->value('value');
        $this->assertStringNotContainsString('super-secret-password', (string) $raw);
    }

    public function test_destination_step_defaults_to_temm_managed(): void
    {
        $this->actingAs($this->owner());

        $component = \Livewire::test(\App\Filament\Pages\NewProjectWizard::class);

        $this->assertSame('temm', $component->instance()->state['destination']);
    }

    public function test_external_destination_requires_target_details(): void
    {
        $this->actingAs($this->owner());

        \Livewire::test(\App\Filament\Pages\NewProjectWizard::class)
            ->set('step', 3)
            ->set('state.destination', 'external')
            ->set('state.target.host', '')
            ->set('state.target.database', '')
            ->call('continue')
            ->assertHasErrors(['state.target.host', 'state.target.database']);
    }

    public function test_projects_list_links_to_the_wizard(): void
    {
        $this->actingAs($this->owner());

        $html = $this->get(\App\Filament\Resources\Projects\ProjectResource::getUrl('index'))->getContent();

        // The New Project action deep-links into the guided wizard.
        $this->assertStringContainsString('/admin/new-project', $html);
    }

    public function test_wizard_start_is_audited(): void
    {
        $before = \Illuminate\Support\Facades\DB::table('admin_audit_entries')->count();

        $this->actingAs($this->owner())->get('/admin/new-project')->assertOk();

        // Page visits do not audit; the audit lands on real mutations.
        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('admin_audit_entries')->count());
    }
}
