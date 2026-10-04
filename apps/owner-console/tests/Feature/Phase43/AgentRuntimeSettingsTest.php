<?php

namespace Tests\Feature\Phase43;

use App\Models\AgentRuntime;
use App\Models\AdminAuditEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\Roles;
use App\Services\Agent\AgentWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Phase43\Concerns\BuildsAgentFixtureProject;
use Tests\TestCase;

class AgentRuntimeSettingsTest extends TestCase
{
    use RefreshDatabase, BuildsAgentFixtureProject;

    protected function adminUser(): User
    {
        return User::create([
            'name' => 'Platform Admin',
            'email' => 'admin-'.Str::random(5).'@example.test',
            'password' => bcrypt('a-very-long-password-1!'),
            'platform_role' => Roles::PLATFORM_ADMIN,
        ]);
    }

    protected function plainUser(): User
    {
        return User::create([
            'name' => 'Plain',
            'email' => 'plain-'.Str::random(5).'@example.test',
            'password' => bcrypt('a-very-long-password-1!'),
            'platform_role' => null,
        ]);
    }

    public function test_page_requires_agents_configure_capability(): void
    {
        $this->actingAs($this->plainUser());
        $this->get('/admin/developer-agent-settings')->assertForbidden();

        $this->actingAs($this->adminUser());
        $this->get('/admin/developer-agent-settings')->assertOk();
    }

    public function test_saving_a_managed_runtime_writes_only_safe_fields_and_audits(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        Livewire::test(\App\Filament\Pages\DeveloperAgentSettings::class)
            ->set('runtimeForm.display_name', 'Stack OpenCode')
            ->set('runtimeForm.mode', 'managed')
            ->set('runtimeForm.enabled', true)
            ->call('saveRuntime')
            ->assertHasNoErrors();

        $runtime = AgentRuntime::where('display_name', 'Stack OpenCode')->firstOrFail();
        $this->assertSame('opencode', $runtime->driver);
        $this->assertNull($runtime->endpoint); // managed derives the endpoint
        $this->assertTrue($runtime->enabled);

        $this->assertNotNull(AdminAuditEntry::where('action', 'AGENT_RUNTIME_CREATED')->where('target_id', $runtime->id)->first());
    }

    public function test_external_runtime_requires_an_endpoint_and_stores_the_secret_encrypted(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        Livewire::test(\App\Filament\Pages\DeveloperAgentSettings::class)
            ->set('runtimeForm.display_name', 'External OC')
            ->set('runtimeForm.mode', 'external')
            ->set('runtimeForm.endpoint', '')
            ->call('saveRuntime')
            ->assertHasErrors(['runtimeForm.endpoint']);

        Livewire::test(\App\Filament\Pages\DeveloperAgentSettings::class)
            ->set('runtimeForm.display_name', 'External OC')
            ->set('runtimeForm.mode', 'external')
            ->set('runtimeForm.endpoint', 'https://agents.example.internal')
            ->set('runtimeForm.auth_secret', 'A-SUPER-SECRET-PASSWORD')
            ->call('saveRuntime')
            ->assertHasNoErrors();

        $runtime = AgentRuntime::where('display_name', 'External OC')->firstOrFail();

        // Encrypted at rest.
        $raw = DB::table('agent_runtimes')->where('id', $runtime->id)->value('auth_secret_encrypted');
        $this->assertStringNotContainsString('A-SUPER-SECRET-PASSWORD', (string) $raw);
        $this->assertSame('A-SUPER-SECRET-PASSWORD', $runtime->auth_secret_encrypted);
    }

    public function test_secret_input_is_write_only_and_never_reaches_the_html_twice(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $component = Livewire::test(\App\Filament\Pages\DeveloperAgentSettings::class)
            ->set('runtimeForm.display_name', 'Write Only')
            ->set('runtimeForm.mode', 'external')
            ->set('runtimeForm.endpoint', 'https://agents.example.internal')
            ->set('runtimeForm.auth_secret', 'WRITE-ONLY-SECRET-99')
            ->call('saveRuntime');

        // After save the form was reset: the secret is not in the HTML.
        $html = $component->html();
        $this->assertStringNotContainsString('WRITE-ONLY-SECRET-99', $html);

        // Editing never repopulates it.
        $runtime = AgentRuntime::where('display_name', 'Write Only')->firstOrFail();
        $component2 = Livewire::test(\App\Filament\Pages\DeveloperAgentSettings::class)
            ->call('editRuntime', $runtime->id);
        $this->assertSame('', $component2->get('runtimeForm.auth_secret'));
        $this->assertStringNotContainsString('WRITE-ONLY-SECRET-99', $component2->html());
    }

    public function test_workbench_requires_agents_view_and_renders_for_runners(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner-'.Str::random(5).'@example.test',
            'password' => bcrypt('a-very-long-password-1!'),
            'platform_role' => Roles::PLATFORM_OWNER,
        ]);
        $this->actingAs($owner);

        $this->get('/admin/developer-agent')->assertOk();

        $plain = $this->plainUser();
        $this->actingAs($plain);
        $this->get('/admin/developer-agent')->assertForbidden();
    }

    public function test_workbench_lists_only_reachable_tasks(): void
    {
        $owner = User::create([
            'name' => 'Owner 2',
            'email' => 'owner2-'.Str::random(5).'@example.test',
            'password' => bcrypt('a-very-long-password-1!'),
            'platform_role' => Roles::PLATFORM_OWNER,
        ]);
        $this->actingAs($owner);

        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $runtime = AgentRuntime::create([
            'driver' => 'opencode', 'display_name' => 'UI Runtime', 'mode' => 'managed',
            'enabled' => false, 'status' => 'untested',
        ]);

        $task = \App\Models\AgentTask::create([
            'code' => 'AGT-8001', 'created_by' => $owner->id, 'project_id' => $project->id,
            'agent_runtime_id' => $runtime->id, 'prompt' => 'demo', 'status' => 'queued',
        ]);

        $response = Livewire::test(\App\Filament\Pages\DeveloperAgent::class);
        $html = $response->html();
        $this->assertStringContainsString('AGT-8001', $html);

        AgentWorkspaceService::deleteTree(AgentWorkspaceService::root());
        $this->cleanupFixtureRepo($repoPath);
    }
}
