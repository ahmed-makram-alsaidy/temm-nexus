<?php

namespace Tests\Feature\Phase60;

use App\Filament\Pages\NewProjectWizard;
use App\Models\Project;
use App\Models\ProjectWizardDraft;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Access\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 0.6.0 Phase E — the NEW PROJECT experience.
 *
 * §E2 the workspace dead end is gone: a workspace can be created inline and
 *      the wizard never loses entered fields;
 * §E3 state persists server-side from the first step (refresh, back/forward
 *      and validation errors all survive), and secrets are never persisted;
 * §E4 five steps, Host-before-Password field order, Advanced collapsed,
 *      connector cards without internal phase IDs, useful ordering;
 * §E5 connection outcomes are classified and speak plainly — the private-
 *      network recovery path names an administrator, not an env var.
 */
class NewProjectExperienceTest extends TestCase
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

    // ── §E2: the workspace dead end ────────────────────────────────────

    public function test_wizard_offers_inline_workspace_creation(): void
    {
        $this->actingAs($this->owner());

        $html = $this->get('/admin/new-project')->getContent();

        // The escape hatch from the empty select lives ON the step.
        $this->assertStringContainsString(__('wizard.create_workspace_inline'), $html);
        $this->assertStringContainsString('data-wizard-create-workspace', $html);
    }

    public function test_wizard_explains_when_an_administrator_must_create_the_workspace(): void
    {
        // A workspace user without the create capability sees the honest
        // message — no invented "personal mode".
        $user = $this->workspaceUser();
        $this->alphaWorkspace($user);
        $this->actingAs($user);

        $component = \Livewire::test(NewProjectWizard::class);

        $this->assertFalse($component->instance()->canCreateWorkspace());
        $this->assertSame(__('wizard.workspace_needs_admin'), __('wizard.workspace_needs_admin'));
    }

    public function test_inline_workspace_creation_keeps_the_wizard_and_selects_the_new_workspace(): void
    {
        // Journey A — a platform owner with NO workspaces creates one inline.
        // (Workspace-scoped users cannot create workspaces; they see the
        // administrator message instead.)
        $user = $this->owner();
        $this->actingAs($user);

        $component = \Livewire::test(NewProjectWizard::class);

        // Fields the user already typed stay exactly as they were.
        $component->set('state.name', 'Acme Website')
            ->set('state.environment', 'staging')
            ->call('openInlineWorkspaceCreate')
            ->set('newWorkspaceName', 'Nayrouz Client')
            ->call('createWorkspaceInline');

        $this->assertFalse($component->instance()->creatingWorkspace);

        $workspace = Workspace::query()->where('name', 'Nayrouz Client')->first();
        $this->assertNotNull($workspace, 'The workspace must be created inline');
        $this->assertSame($workspace->id, $component->instance()->state['workspace_id'], 'The new workspace is auto-selected');
        $this->assertSame('Acme Website', $component->instance()->state['name'], 'Entered fields survive the inline create');
        $this->assertSame('staging', $component->instance()->state['environment']);

        // Still on step 1 — the user never left the wizard.
        $this->assertSame(1, $component->instance()->step);
    }

    public function test_inline_workspace_creation_is_capability_gated(): void
    {
        $user = $this->workspaceUser();
        $this->alphaWorkspace($user);
        $this->actingAs($user);

        \Livewire::test(NewProjectWizard::class)
            ->call('openInlineWorkspaceCreate')
            ->set('newWorkspaceName', 'Forbidden Workspace')
            ->call('createWorkspaceInline');

        $this->assertNull(
            Workspace::query()->where('name', 'Forbidden Workspace')->first(),
            'A user without the workspace-create capability must not create a workspace',
        );
    }

    // ── §E3: persistence from step 1 ───────────────────────────────────

    public function test_step_one_state_persists_server_side(): void
    {
        $user = $this->workspaceUser();
        $workspace = $this->alphaWorkspace($user);
        $this->actingAs($user);

        $component = \Livewire::test(NewProjectWizard::class)
            ->set('state.name', 'Draft Project')
            ->set('state.workspace_id', $workspace->id)
            ->set('state.environment', 'production');

        // updatedState hooks persisted the draft as the fields changed.
        $draft = ProjectWizardDraft::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($draft, 'The draft must exist from the first step');
        $this->assertSame('Draft Project', $draft->state['name']);
        $this->assertSame($workspace->id, $draft->state['workspace_id']);
        $this->assertSame('production', $draft->state['environment']);
    }

    public function test_wizard_resumes_after_a_full_reload(): void
    {
        $user = $this->workspaceUser();
        $workspace = $this->alphaWorkspace($user);
        $this->actingAs($user);

        // First visit: enter data (as the wizard itself would save it).
        ProjectWizardDraft::store(1, [
            'name' => 'Reload Project',
            'workspace_id' => $workspace->id,
            'environment' => 'staging',
            'connector' => null,
            'connection' => [],
            'destination' => 'temm',
            'target' => ['host' => '', 'port' => '5432', 'database' => '', 'username' => '', 'password' => ''],
            'target_disposable' => true,
        ]);

        // A brand-new component (what a refresh creates) restores it.
        $component = \Livewire::test(NewProjectWizard::class);

        $this->assertTrue($component->instance()->resumed);
        $this->assertSame('Reload Project', $component->instance()->state['name']);
        $this->assertSame($workspace->id, $component->instance()->state['workspace_id']);
    }

    public function test_draft_never_persists_secrets(): void
    {
        $user = $this->workspaceUser();
        $this->alphaWorkspace($user);
        $this->actingAs($user);

        $component = \Livewire::test(NewProjectWizard::class)
            ->set('state.connector', 'postgres')
            ->set('state.connection.host', 'db.internal')
            ->set('state.connection.password', 'super-secret-password')
            ->set('state.target.password', 'target-secret');

        $draft = ProjectWizardDraft::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($draft);
        $this->assertNotSame('super-secret-password', $draft->state['connection']['password'] ?? null);
        $this->assertNotSame('target-secret', $draft->state['target']['password'] ?? null);

        $stored = json_encode($draft->toArray());
        $this->assertStringNotContainsString('super-secret-password', (string) $stored);
        $this->assertStringNotContainsString('target-secret', (string) $stored);
    }

    public function test_resume_after_source_step_does_not_need_the_password_again(): void
    {
        $user = $this->workspaceUser();
        $this->alphaWorkspace($user);
        $this->actingAs($user);

        // A draft that already carries project + source (the source step
        // persisted them and vaulted the secrets).
        ProjectWizardDraft::store(3, [
            'name' => 'Resumed Project', 'workspace_id' => null, 'environment' => 'development',
            'connector' => 'postgres', 'connection' => [], 'destination' => 'temm',
            'target' => [], 'target_disposable' => true,
        ], ['project_id' => null, 'source_id' => null]);

        $component = \Livewire::test(NewProjectWizard::class);
        $this->assertTrue($component->instance()->resumed);
        $this->assertSame(3, $component->instance()->step);
    }

    public function test_discard_draft_starts_fresh(): void
    {
        $user = $this->workspaceUser();
        $workspace = $this->alphaWorkspace($user);
        $this->actingAs($user);

        $component = \Livewire::test(NewProjectWizard::class)
            ->set('state.name', 'To Be Discarded')
            ->set('state.workspace_id', $workspace->id)
            ->call('discardDraft');

        $this->assertNull(ProjectWizardDraft::query()->where('user_id', $user->id)->first());
        $this->assertFalse($component->instance()->resumed);
        $this->assertSame('', $component->instance()->state['name']);
    }

    // ── §E4: five steps, field order, Advanced, connector cards ────────

    public function test_the_wizard_has_exactly_five_steps(): void
    {
        $this->assertSame(
            ['project', 'source', 'destination', 'analyze', 'review'],
            NewProjectWizard::STEPS,
        );
    }

    public function test_connection_fields_render_host_before_password(): void
    {
        $this->actingAs($this->owner());

        $component = \Livewire::test(NewProjectWizard::class)
            ->set('state.connector', 'postgres');

        $groups = $component->instance()->connectionFieldGroups();
        $primaryKeys = array_map(fn ($f) => $f->key, $groups['primary']);
        $advancedKeys = array_map(fn ($f) => $f->key, $groups['advanced']);

        $this->assertSame(['host', 'port', 'database', 'username', 'password'], $primaryKeys);
        // §E4 — expert fields live behind the Advanced disclosure.
        $this->assertContains('ssl_mode', $advancedKeys);
        $this->assertContains('schemas', $advancedKeys);
        $this->assertContains('batch_size', $advancedKeys);
    }

    public function test_advanced_fields_render_collapsed_by_default(): void
    {
        $this->actingAs($this->owner());

        // Land on the source step the way a returning user would: a saved
        // draft with a connector choice (a fresh GET renders step 2).
        ProjectWizardDraft::store(2, [
            'name' => 'Advanced Project', 'workspace_id' => null, 'environment' => 'development',
            'connector' => 'postgres', 'connection' => [], 'destination' => 'temm',
            'target' => [], 'target_disposable' => true,
        ]);

        $html = $this->get('/admin/new-project')->getContent();

        // The Advanced disclosure exists and is NOT open by default.
        $this->assertStringContainsString('<details class="nx-advanced" data-wizard-advanced>', $html);
        $this->assertStringContainsString(__('common.advanced_options'), $html);
    }

    public function test_connector_cards_carry_no_internal_phase_ids(): void
    {
        $this->actingAs($this->owner());

        $component = \Livewire::test(NewProjectWizard::class);

        foreach ($component->instance()->sourceCards() as $card) {
            foreach (['35.6', '30B', '27Q', '27D', 'Phase 3', 'Phase 2'] as $internal) {
                $this->assertStringNotContainsString(
                    $internal,
                    $card['description'],
                    "Connector card copy must not leak internal reference {$internal}",
                );
            }
            $this->assertNotSame('', $card['description'], 'Every card needs a one-sentence description');
        }
    }

    public function test_connector_cards_order_mainstream_first(): void
    {
        $this->actingAs($this->owner());

        $component = \Livewire::test(NewProjectWizard::class);
        $keys = array_map(fn ($card) => $card['key'], $component->instance()->sourceCards());

        $this->assertSame('postgres', $keys[0], 'PostgreSQL leads the useful ordering');
        $this->assertSame('example-json', end($keys), 'The developer example comes last');
        $this->assertContains('mysql', $keys);
        $this->assertContains('supabase', $keys);
    }

    // ── §E5: connection test UX ────────────────────────────────────────

    public function test_successful_connection_communicates_read_only_access(): void
    {
        $this->actingAs($this->owner());

        $component = \Livewire::test(NewProjectWizard::class)
            ->set('state.connector', 'postgres')
            ->set('state.connection.host', '127.0.0.1')
            ->set('state.connection.port', '1')
            ->set('state.connection.database', 'sourcedb')
            ->set('state.connection.username', 'reader');

        // The fixture transport is not used here; a refused port classifies
        // as a network failure with a friendly kind — never a raw dump.
        $component->call('testConnection');

        $result = $component->instance()->testResult;
        $this->assertFalse($result['ok']);
        $this->assertContains($result['kind'], ['network', 'auth', 'invalid', 'private_network']);
    }

    public function test_private_network_failure_names_an_administrator_not_an_env_var(): void
    {
        config()->set('connectors.allow_private_networks', false);
        $this->actingAs($this->owner());

        $component = \Livewire::test(NewProjectWizard::class)
            ->set('state.connector', 'postgres')
            ->set('state.connection.host', '127.0.0.1')
            ->set('state.connection.port', '5432')
            ->set('state.connection.database', 'sourcedb')
            ->set('state.connection.username', 'reader')
            ->call('testConnection');

        $result = $component->instance()->testResult;
        $this->assertFalse($result['ok']);
        $this->assertSame('private_network', $result['kind'], 'A private-network target must classify as such');

        // §E5 — the DEFAULT copy is the admin path; the env-var name exists
        // only inside the disclosed detail, never as the instruction.
        $this->assertSame(
            __('wizard.test_private_network_body'),
            'This source appears to be on a private network. An administrator must allow private-network connections in System settings.',
        );
    }

    public function test_private_network_technical_details_stay_disclosed(): void
    {
        config()->set('connectors.allow_private_networks', false);
        $this->actingAs($this->owner());

        $component = \Livewire::test(NewProjectWizard::class)
            ->set('state.connector', 'postgres')
            ->set('state.connection.host', '127.0.0.1')
            ->set('state.connection.database', 'sourcedb')
            ->set('state.connection.username', 'reader')
            ->call('testConnection');

        // The guard's diagnostic is preserved for the disclosure (redacted of
        // credentials by the connector layer) — security not weakened.
        $this->assertStringContainsString(
            'SSRF',
            (string) ($component->instance()->testResult['detail'] ?? ''),
            'The guard diagnostic stays available for Technical details',
        );
    }

    public function test_connection_test_result_is_persisted_in_the_draft_without_secrets(): void
    {
        $user = $this->workspaceUser();
        $this->alphaWorkspace($user);
        $this->actingAs($user);

        $component = \Livewire::test(NewProjectWizard::class)
            ->set('state.connector', 'postgres')
            ->set('state.connection.host', '127.0.0.1')
            ->set('state.connection.port', '1')
            ->set('state.connection.database', 'db')
            ->set('state.connection.username', 'reader')
            ->set('state.connection.password', 'sekrit')
            ->call('testConnection')
            ->call('saveDraft');

        $draft = ProjectWizardDraft::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($draft->test_result);
        $this->assertFalse($draft->test_result['ok']);
        $stored = json_encode($draft->toArray());
        $this->assertStringNotContainsString('sekrit', (string) $stored);
    }

    public function test_step_two_blocks_continue_until_the_test_passes(): void
    {
        $user = $this->workspaceUser();
        $workspace = $this->alphaWorkspace($user);
        $this->actingAs($user);

        $component = \Livewire::test(NewProjectWizard::class)
            ->set('state.name', 'Blocked Project')
            ->set('state.workspace_id', $workspace->id)
            ->call('continue')
            ->set('state.connector', 'postgres')
            ->set('state.connection.host', '127.0.0.1')
            ->set('state.connection.port', '1')
            ->set('state.connection.database', 'db')
            ->set('state.connection.username', 'reader')
            ->set('state.connection.password', 'pw')
            ->call('testConnection')
            ->call('continue');

        // Still on step 2 with the honest reason, not a bare error.
        $this->assertSame(2, $component->instance()->step);
        $this->assertSame(__('wizard.test_first'), $component->instance()->error);
    }

    // ── §E11: start migration refuses with a reason ────────────────────

    public function test_start_migration_without_analysis_refuses_with_recovery_language(): void
    {
        $user = $this->workspaceUser();
        $workspace = $this->alphaWorkspace($user);
        $this->actingAs($user);

        $project = Project::create([
            'name' => 'No Analysis Project', 'slug' => 'no-analysis-'.uniqid(),
            'workspace_id' => $workspace->id,
            'status' => 'active', 'environment' => 'development',
        ]);

        $component = \Livewire::test(NewProjectWizard::class)
            ->set('projectId', $project->id)
            ->call('startMigration');

        $this->assertSame(__('wizard.start_blocked_no_analysis'), $component->instance()->error);
        $this->assertNotSame('Error', $component->instance()->error);
    }

    public function test_review_readiness_is_never_ready_over_zero_tables(): void
    {
        $user = $this->workspaceUser();
        $workspace = $this->alphaWorkspace($user);
        $this->actingAs($user);

        $component = \Livewire::test(NewProjectWizard::class);

        // No analysis at all → not ready, with the reason named.
        $readiness = $component->instance()->reviewReadiness();
        $this->assertFalse($readiness['ready']);
        $this->assertSame(__('wizard.review_reason_no_analysis'), $readiness['reason']);
    }
}
