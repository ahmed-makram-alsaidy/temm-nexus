<?php

namespace Tests\Feature\Phase24;

use App\Models\OnboardingSession;
use App\Services\ControlPlane\OnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase24\Concerns\BuildsEngineFixture;
use Tests\TestCase;

/** Phase 24B — Onboarding wizard: create + import flows, resume, isolation. */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEngineFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBase();
        $this->actingAs($this->admin);
    }

    public function test_create_flow_defers_creation_to_final_confirmation(): void
    {
        $session = OnboardingService::start($this->admin->id, 'create');
        OnboardingService::saveStep($session, 1, ['name' => 'My New App', 'slug' => 'my-new-app']);

        // Nothing created yet.
        $this->assertDatabaseMissing('projects', ['slug' => 'my-new-app']);

        $session->refresh();
        $session->update(['state' => array_merge($session->state, [
            'name' => 'My New App', 'slug' => 'my-new-app', 'db_name' => 'my_new_app_db',
            'secrets' => [['name' => 'WHATSAPP_TOKEN', 'value' => 'wa-token-1', 'category' => 'whatsapp']],
        ])]);

        $project = OnboardingService::completeCreate($session->fresh());
        $this->assertDatabaseHas('projects', ['slug' => 'my-new-app']);
        $this->assertSame(3, $project->environments()->count(), 'canonical environments created with project');
        $this->assertDatabaseHas('project_secrets', ['project_id' => $project->id, 'name' => 'WHATSAPP_TOKEN']);
        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_import_flow_creates_read_only_source(): void
    {
        $session = OnboardingService::start($this->admin->id, 'import');
        $session->update(['state' => [
            'name' => 'Imported Co', 'slug' => 'imported-co',
            'source_type' => 'supabase',
            'source_connection' => ['host' => 'postgres', 'port' => 5432, 'database' => 'some_snapshot', 'username' => 'postgres'],
            'source_secret_refs' => ['password' => 'SOURCE_DB_PASSWORD'],
        ]]);

        $result = OnboardingService::completeImport($session);
        $project = $result['project'];
        $source = $result['source'];

        $this->assertNotNull($source);
        $this->assertTrue($source->read_only);
        $this->assertSame('supabase', $source->type);
        $this->assertSame(['password' => 'SOURCE_DB_PASSWORD'], $source->secret_refs, 'only secret NAMES are stored');
        $this->assertStringNotContainsString('password', json_encode($source->connection), 'connection record never carries credentials');
    }

    public function test_wizard_state_persists_for_resume(): void
    {
        $session = OnboardingService::start($this->admin->id, 'create');
        OnboardingService::saveStep($session, 3, ['db_name' => 'resumable_db']);

        $resumed = OnboardingService::resume($this->admin->id);
        $this->assertNotNull($resumed);
        $this->assertSame($session->id, $resumed->id);
        $this->assertSame(3, $resumed->current_step);
        $this->assertSame('resumable_db', $resumed->state['db_name']);

        OnboardingService::abandon($resumed);
        $this->assertNull(OnboardingService::resume($this->admin->id));
    }

    public function test_flow_is_validated(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        OnboardingService::start($this->admin->id, 'clone-production');
    }
}
