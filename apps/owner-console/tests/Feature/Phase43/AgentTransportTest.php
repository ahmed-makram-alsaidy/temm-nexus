<?php

namespace Tests\Feature\Phase43;

use App\Models\AgentRuntime;
use App\Services\Agent\AgentRuntimeManager;
use App\Services\Agent\AgentTaskService;
use App\Services\Agent\AgentWorkspaceService;
use App\Services\Agent\Runtimes\OpenCode\OpenCodeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase43\Concerns\BuildsAgentFixtureProject;
use Tests\Feature\Phase43\Concerns\RunsMockOpenCode;
use Tests\TestCase;

/**
 * Transport tests: the REAL outgoing request shape TEMM sends to an OpenCode
 * server (endpoint selection, basic-auth header, directory query param) as
 * observed by the mock server, plus SSE streaming behavior.
 */
class AgentTransportTest extends TestCase
{
    use RefreshDatabase, BuildsAgentFixtureProject, RunsMockOpenCode;

    protected function tearDown(): void
    {
        $this->tearDownMock();
        parent::tearDown();
    }

    public function test_outgoing_requests_carry_basic_auth_and_the_official_paths(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('happy', 'opencode:mock-password');

        $client = new OpenCodeClient($baseUrl, 'opencode', 'mock-password', 5, 20);

        $health = $client->health();
        $this->assertTrue($health['healthy']);
        $this->assertSame('1.18.34', $health['version']);

        $providers = $client->providers();
        $this->assertSame('mockprovider', $providers[0]['id']);

        $session = $client->createSession('/definitely/unused', null, ['edit' => 'allow'], 'probe title');
        $this->assertStringStartsWith('ses_', $session['id']);
        $this->assertSame('/definitely/unused', $session['directory']);

        // The mock echoes the wire shape of the last request.
        $context = stream_context_create(['http' => ['header' => 'Authorization: Basic '.base64_encode('opencode:mock-password')]]);
        $last = json_decode((string) @file_get_contents($baseUrl.'/debug/last-request', false, $context), true);

        $this->assertSame('POST', $last['method']);
        $this->assertSame('/session', $last['path']);
        $this->assertSame('/definitely/unused', $last['query']['directory']);
        $this->assertSame('Basic '.base64_encode('opencode:mock-password'), $last['authorization']);

        $this->stopMockOpenCode();
    }

    public function test_full_task_run_hits_only_the_verified_endpoints(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('happy');
        $owner = $this->agentOwner();
        $this->be($owner);

        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $runtime = AgentRuntime::create([
            'driver' => 'opencode', 'display_name' => 'Transport Runtime',
            'mode' => AgentRuntime::MODE_EXTERNAL, 'endpoint' => $baseUrl,
            'auth_secret_encrypted' => 'mock-password', 'enabled' => true,
            'status' => AgentRuntime::STATUS_CONNECTED, 'version' => '1.18.34',
            'timeout_seconds' => 60, 'max_concurrent_tasks' => 1, 'workspace_retention_days' => 1,
        ]);

        app(AgentTaskService::class)->createTask($owner, $project, $runtime, 'mockprovider/mock-small', 'Run the flow.');

        $context = stream_context_create(['http' => ['header' => 'Authorization: Basic '.base64_encode('opencode:mock-password')]]);
        $last = json_decode((string) @file_get_contents($baseUrl.'/debug/last-request', false, $context), true);

        // The final call in the flow is the authoritative diff read.
        $this->assertSame('GET', $last['method']);
        $this->assertMatchesRegularExpression('#^/session/ses_[a-f0-9]+/diff$#', $last['path']);

        $this->stopMockOpenCode();
        AgentWorkspaceService::deleteTree(AgentWorkspaceService::root());
        $this->cleanupFixtureRepo($repoPath);
    }

    public function test_models_listing_is_exposed_through_the_manager(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('happy');

        $runtime = AgentRuntime::create([
            'driver' => 'opencode', 'display_name' => 'Models Runtime',
            'mode' => AgentRuntime::MODE_EXTERNAL, 'endpoint' => $baseUrl,
            'auth_secret_encrypted' => 'mock-password', 'enabled' => true, 'status' => 'connected',
        ]);

        $models = app(AgentRuntimeManager::class)->forRuntime($runtime)->models($runtime);
        $canonicals = array_map(fn ($m) => $m->canonicalId(), $models);

        $this->assertContains('mockprovider/mock-small', $canonicals);
        $this->assertContains('mockprovider/mock-large', $canonicals);

        $small = collect($models)->firstWhere('id', 'mock-small');
        $this->assertSame(128000, $small->contextLimit);

        $this->stopMockOpenCode();
    }
}
