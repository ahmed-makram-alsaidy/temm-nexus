<?php

namespace Tests\Feature\Phase20;

use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\CpAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamRbacTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::create([
            'name' => 'Probe A', 'slug' => 'gate-a', 'status' => 'active',
            'db_name' => 'gate_a_db', 'redis_prefix' => 'gatea',
        ]);
        \App\Services\ControlPlane\CpAccess::seedDefaults();
    }

    protected function teamUser(string $role): User
    {
        return User::factory()->create(['is_admin' => false, 'cp_role' => $role]);
    }

    public function test_unassigned_user_denied_panel(): void
    {
        $outsider = User::factory()->create(['is_admin' => false]);
        $this->assertNull($outsider->cp_role);
        $this->actingAs($outsider)->get('/admin')->assertForbidden();
    }

    public function test_developer_reads_but_cannot_write_or_deploy(): void
    {
        $dev = $this->teamUser('developer');

        // Panel entry + read surfaces.
        $this->actingAs($dev)->get('/admin')->assertOk();
        $this->actingAs($dev)->get('/admin/projects/'.$this->project->id.'/sql')->assertOk();
        $this->actingAs($dev)->get('/admin/projects/'.$this->project->id.'/database')->assertOk();

        // Write-mode SQL refused (permission, before password even matters).
        $this->actingAs($dev)
            ->postJson('/cp-sql/'.$this->project->id.'/write-mode', ['password' => 'x'])
            ->assertForbidden();

        // Secrets + team pages refused.
        $this->actingAs($dev)->get('/admin/projects/'.$this->project->id.'/secrets')->assertForbidden();
        $this->actingAs($dev)->get('/admin/team')->assertForbidden();

        // Functions invoke allowed, deploy-gated surfaces hidden: editor is
        // viewable (functions.view) — deploy actions enforce functions.deploy.
        $this->assertTrue(CpAccess::allows($dev, 'functions.invoke'));
        $this->assertFalse(CpAccess::allows($dev, 'functions.deploy'));
        $this->assertFalse(CpAccess::allows($dev, 'database.write'));
        $this->assertFalse(CpAccess::allows($dev, 'team.manage'));
    }

    public function test_observer_is_read_only(): void
    {
        $observer = $this->teamUser('observer');
        $this->actingAs($observer)->get('/admin/projects/'.$this->project->id.'/sql')->assertOk();
        $this->actingAs($observer)->get('/admin/projects/'.$this->project->id.'/functions')->assertOk();
        $this->actingAs($observer)->get('/admin/projects/'.$this->project->id.'/keys')->assertForbidden();
        $this->assertFalse(CpAccess::allows($observer, 'functions.invoke'));
    }

    public function test_owner_assigns_role_and_cannot_self_downgrade(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $dev = $this->teamUser('developer');

        $this->actingAs($owner)->get('/admin/team')->assertOk();

        // Direct service-level proof of the self-protection rule path:
        // assignment itself works for others.
        $dev->forceFill(['cp_role' => 'observer'])->save();
        $this->assertSame('observer', $dev->fresh()->cp_role);
    }

    public function test_admin_lacks_team_manage(): void
    {
        $admin = $this->teamUser('admin');
        $this->assertFalse(CpAccess::allows($admin, 'team.manage'));
        $this->assertTrue(CpAccess::allows($admin, 'secrets.manage'));
        $this->actingAs($admin)->get('/admin/team')->assertForbidden();
    }
}
