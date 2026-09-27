<?php

namespace Tests\Feature\Phase24;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\ControlPlane\ClientSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase24\Concerns\BuildsEngineFixture;
use Tests\TestCase;

/** Phase 24I — Generated client setup: safe config, real SDK APIs, SSRF guard. */
class ClientSetupTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEngineFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBase();
        $this->actingAs($this->admin);
    }

    public function test_config_is_environment_specific_and_secret_free(): void
    {
        $env = new ProjectEnvironment([
            'project_id' => $this->projectA->id, 'name' => 'Staging', 'slug' => 'staging',
            'type' => 'staging', 'status' => 'active',
            'api_base_url' => 'https://api-staging.fixture-a.test',
        ]);
        $config = ClientSetupService::configFor($this->projectA, $env);

        $this->assertSame('https://api-staging.fixture-a.test', $config['api_url']);
        $this->assertSame('fixture-a', $config['project_slug']);
        $this->assertSame('staging', $config['environment']);

        // No secret material may ever appear in generated config.
        $encoded = json_encode($config);
        $this->assertStringNotContainsStringIgnoringCase('secret', $encoded);
        $this->assertStringNotContainsStringIgnoringCase('password', $encoded);
    }

    public function test_snippets_use_real_phase22_sdk_apis(): void
    {
        $snippets = ClientSetupService::snippets($this->projectA);

        $this->assertArrayHasKey('JavaScript', $snippets);
        $this->assertArrayHasKey('React / Next.js', $snippets);
        $this->assertArrayHasKey('Flutter / Dart', $snippets);
        $this->assertArrayHasKey('PHP (server-to-server)', $snippets);

        // Phase 22 SDK API surface — not invented method names.
        $this->assertStringContainsString("auth.login(", $snippets['JavaScript']);
        $this->assertStringContainsString("auth.me()", $snippets['JavaScript']);
        $this->assertStringContainsString("functions.invoke(", $snippets['JavaScript']);
        $this->assertStringContainsString('storage.upload(', $snippets['JavaScript']);
        $this->assertStringContainsString('auth.login(email:', $snippets['Flutter / Dart']);
        $this->assertStringContainsString('functions()->invoke(', $snippets['PHP (server-to-server)']);
    }

    public function test_env_files_contain_public_values_only(): void
    {
        $files = ClientSetupService::envFiles($this->projectA);
        $this->assertArrayHasKey('.env.example (JS/PHP)', $files);
        $this->assertArrayHasKey('dart-define', $files);
        $this->assertArrayHasKey('Next.js public env', $files);

        foreach ($files as $content) {
            $this->assertStringContainsString('CLIENT-SAFE', $content);
        }
    }

    public function test_connection_test_blocks_non_project_hosts(): void
    {
        $env = new ProjectEnvironment([
            'project_id' => $this->projectA->id, 'name' => 'Dev', 'slug' => 'dev2', 'type' => 'development', 'status' => 'active',
            'api_base_url' => 'http://169.254.169.254', // metadata SSRF target
        ]);
        $result = ClientSetupService::testConnection($this->projectA, $env);

        $this->assertSame('blocked', $result['checks'][0]['status'], 'SSRF guard must block hosts outside the project allowlist');
        $this->assertDatabaseHas('admin_audit_entries', ['action' => 'CLIENT_CONNECTION_TESTED', 'project_id' => $this->projectA->id]);
    }

    public function test_connection_test_handles_missing_api_url(): void
    {
        $bare = Project::create(['name' => 'No Domain', 'slug' => 'no-domain-'.uniqid(), 'status' => 'active']);
        $result = ClientSetupService::testConnection($bare, null);
        $this->assertSame('unavailable', $result['overall']);
    }
}
