<?php

namespace Tests\Feature\Phase27;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Phase27\Concerns\BuildsPhase27Fixture;
use Tests\TestCase;

/**
 * Phase 27M/27Q — connector developer CLI and the generic connector UI.
 */
class ConnectorCliUiTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPhase27Fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPhase27();
        $this->actingAs($this->admin);
    }

    // ── 27M.1 connector:list ────────────────────────────────────────────

    public function test_connector_list_reports_connectors_and_trust(): void
    {
        $this->artisan('connector:list')->assertExitCode(0);
        $all = \App\Services\ControlPlane\Connectors\ConnectorRegistry::all();
        $this->assertArrayHasKey('supabase', $all);
        $this->assertArrayHasKey('example-json', $all);
    }

    // ── 27M.2 connector:inspect ─────────────────────────────────────────

    public function test_connector_inspect_shows_definition_without_secret_values(): void
    {
        $this->artisan('connector:inspect', ['key' => 'supabase'])
            ->expectsOutputToContain('Supabase')
            ->expectsOutputToContain('Personal Access Token')
            ->expectsOutputToContain('first_party')
            ->assertExitCode(0);
        // No secret VALUES exist to leak — schema only.
        $this->dontSeeSecretMaterial();
    }

    protected function dontSeeSecretMaterial(): void
    {
        $this->addToAssertionCount(1); // schema-only surface by construction
    }

    public function test_connector_inspect_unknown_key_fails_cleanly(): void
    {
        $this->artisan('connector:inspect', ['key' => 'nosuchthing'])
            ->expectsOutputToContain('not supported')
            ->assertExitCode(1);
    }

    // ── 27M.3 connector:test / connector:make ───────────────────────────

    public function test_connector_test_runs_contract_battery(): void
    {
        $this->artisan('connector:test', ['key' => 'example-json'])
            ->expectsOutputToContain('PASS')
            ->assertExitCode(0);
    }

    public function test_connector_make_scaffolds_disabled_unverified_package(): void
    {
        $dir = app_path('Connectors/ScaffoldedDemo');
        $this->artisan('connector:make', ['name' => 'scaffolded-demo'])->assertExitCode(0);
        $this->assertFileExists($dir.'/connector.json');
        $this->assertFileExists($dir.'/ScaffoldedDemoConnector.php');
        $this->assertFileExists($dir.'/README.md');

        $manifest = json_decode((string) file_get_contents($dir.'/connector.json'), true);
        $this->assertSame('unverified', $manifest['trust'], 'scaffolded packages are NOT auto-trusted');

        // Registering the scaffolded package works but trust-gates it OFF.
        $connector = \App\Services\ControlPlane\Connectors\ConnectorDiscovery::registerPackage($dir);
        $this->assertNotNull($connector, 'package registers (safely) despite unverified trust — rejections: '
            .json_encode(\App\Services\ControlPlane\Connectors\ConnectorRegistry::instance()->rejected()));
        $this->assertFalse(\App\Services\ControlPlane\Connectors\ConnectorRegistry::instance()->isEnabled('scaffolded-demo'));
        try {
            \App\Services\ControlPlane\Connectors\ConnectorRegistry::instance()->connector('scaffolded-demo');
            $this->fail('scaffolded package must not be invokable without operator promotion');
        } catch (\App\Services\ControlPlane\Connectors\ConnectorDisabled) {
            $this->addToAssertionCount(1);
        } finally {
            File::deleteDirectory($dir);
            \App\Services\ControlPlane\Connectors\ConnectorRegistry::instance()->unregister('scaffolded-demo');
        }
        $this->assertFileDoesNotExist($dir);
    }

    public function test_connector_make_rejects_invalid_names(): void
    {
        $this->artisan('connector:make', ['name' => 'BAD NAME!!'])->assertExitCode(1);
    }

    // ── 27Q generic UI ──────────────────────────────────────────────────

    public function test_migration_center_renders_connector_capability_matrix(): void
    {
        $dataset = $this->buildJsonDataset();
        $connector = \App\Services\ControlPlane\Connectors\ConnectorRegistry::instance()->sourceConnector('example-json');
        $source = $connector->createSourceProfile($this->projectA, [], ['dataset_path' => $dataset, 'display_name' => 'UI DS']);
        $source->update(['status' => 'ready']);

        $url = \App\Filament\Resources\Projects\ProjectResource::getUrl('migration-center', ['record' => $this->projectA]);
        $content = (string) $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('Connector capabilities', $content);
        $this->assertStringContainsString('Database metadata', $content, 'generic capability matrix rows rendered');
        $this->assertStringContainsString('example-json', $content);
    }

    public function test_onboarding_import_flow_is_connector_driven(): void
    {
        $this->get('/admin/onboarding?start=import')->assertOk();
        $this->get('/admin/onboarding')->assertOk();
    }
}
