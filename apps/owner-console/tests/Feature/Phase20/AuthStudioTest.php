<?php

namespace Tests\Feature\Phase20;

use App\Models\Project;
use App\Models\ProjectAuthConfig;
use App\Models\User;
use App\Services\ControlPlane\AuthBootstrapService;
use App\Services\ControlPlane\ProjectConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthStudioTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->project = Project::create([
            'name' => 'Probe A', 'slug' => 'gate-a', 'status' => 'active',
            'db_name' => 'gate_a_db', 'redis_prefix' => 'gatea',
        ]);
    }

    public function test_bootstrap_creates_roles_permissions_tables(): void
    {
        $conn = ProjectConnectionManager::connection($this->project);
        DB::connection($conn)->statement('DROP TABLE IF EXISTS roles CASCADE');
        DB::connection($conn)->statement('DROP TABLE IF EXISTS permissions CASCADE');
        // Bootstrap targets the conventional names; prove mechanics on copies.
        $created = AuthBootstrapService::ensureTables($this->project);
        // gate-a has neither table → both created (idempotent on re-run).
        $this->assertContains('roles', $created);
        $this->assertContains('permissions', $created);
        $again = AuthBootstrapService::ensureTables($this->project);
        $this->assertSame([], $again);

        // Cleanup: tables are disposable test artifacts in the gate fixture DB.
        DB::connection($conn)->statement('DROP TABLE IF EXISTS roles CASCADE');
        DB::connection($conn)->statement('DROP TABLE IF EXISTS permissions CASCADE');
    }

    public function test_security_page_renders_and_saves_config(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/projects/'.$this->project->id.'/auth-security');
        $response->assertOk();
        $response->assertSee('Email templates', false);

        $cfg = ProjectAuthConfig::query()->where('project_id', $this->project->id)->first();
        $this->assertNotNull($cfg);
        $this->assertSame(10, $cfg->password_policy['min_length']);

        // Vault-ref validation rejects raw secrets.
        try {
            \App\Services\ControlPlane\SecretService::validateName('raw-secret!!');
            $this->fail('Expected 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_template_preview_interpolates_safely(): void
    {
        $html = \App\Filament\Resources\Projects\Pages\ProjectAuthSecurity::renderPreview(
            '<p>Hi {{name}}, click {{link}} ({{expiry}}). Unknown: {{nope}}.</p>'
        );
        $this->assertStringContainsString('Ada Example', $html);
        $this->assertStringNotContainsString('{{', $html);
    }
}
