<?php

namespace Tests\Feature\Phase40;

use App\Models\AdminAuditEntry;
use App\Models\AiModelProfile;
use App\Models\AiProviderConfig;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\Access\Access;
use App\Services\Ai\AiContext;
use App\Services\Ai\ConversationEngine;
use App\Services\Ai\ModelRouter;
use App\Services\Ai\ToolDispatcher;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\Tools\ReadToolHandlers;
use App\Services\ControlPlane\Ai\FakeAiDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase40\Concerns\BuildsTenantFixture;
use Tests\TestCase;

/**
 * 0.4.0 §17/§18/§19/§24/§29/§30 — Nexus Copilot: the read-tool layer, model
 * routing, the conversation loop, and the audit trail.
 *
 * The security-critical assertions here are that tool EXECUTION enforces the
 * acting user's capability at execution time, and that a denial is reported
 * rather than silently ignored.
 */
class NexusCopilotTest extends TestCase
{
    use BuildsTenantFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTenantFixture();
        FakeAiDriver::reset();
    }

    protected function tearDown(): void
    {
        FakeAiDriver::reset();
        parent::tearDown();
    }

    /** An enabled fake provider, so no network call is ever made. */
    protected function fakeProvider(?string $model = 'fake-model'): AiProviderConfig
    {
        return AiProviderConfig::create([
            'provider' => 'fake',
            'display_name' => 'Test Provider',
            'model' => $model,
            'enabled' => true,
            'status' => 'ready',
            'timeout_seconds' => 10,
            'max_output_tokens' => 256,
        ]);
    }

    // ── Tool execution is real and grounded ────────────────────────────

    public function test_a_platform_read_tool_returns_real_measured_data(): void
    {
        $context = AiContext::platform(Access::for($this->platformOwner));
        $dispatcher = new ToolDispatcher($context);
        $handlers = (new ReadToolHandlers($context))->map();

        $result = $dispatcher->dispatch('get_platform_health', [], $handlers['get_platform_health']);

        $this->assertTrue($result['ok'], 'A permitted read tool was refused.');
        // Three projects exist in the fixture, all reachable by the owner.
        $this->assertSame(3, $result['data']['projects_total']);
    }

    public function test_a_project_read_tool_reads_only_the_project_in_context(): void
    {
        $context = AiContext::project(Access::for($this->alphaOwner), $this->alphaWeb);
        $dispatcher = new ToolDispatcher($context);
        $handlers = (new ReadToolHandlers($context))->map();

        $result = $dispatcher->dispatch('get_project_summary', [], $handlers['get_project_summary']);

        $this->assertTrue($result['ok']);
        $this->assertSame($this->alphaWeb->name, $result['data']['name']);
        $this->assertNotSame($this->betaCrm->name, $result['data']['name']);
    }

    public function test_a_project_tool_refuses_when_scope_is_platform(): void
    {
        $context = AiContext::platform(Access::for($this->platformOwner));
        $dispatcher = new ToolDispatcher($context);

        // A project tool must not run without a project in context, even for a
        // platform owner — there would be nothing to read.
        $this->assertFalse($dispatcher->canRun('get_project_summary'));
        $this->assertNotContains('get_project_summary', $dispatcher->availableTools());
    }

    // ── §17: execution-time capability enforcement ─────────────────────

    public function test_a_project_viewer_cannot_run_a_tool_their_role_does_not_hold(): void
    {
        // A viewer holds projects.view but not audit.view at project scope.
        $context = AiContext::project(Access::for($this->alphaViewer), $this->alphaBooking);
        $dispatcher = new ToolDispatcher($context);

        $this->assertTrue($dispatcher->canRun('get_project_summary'), 'A viewer should read their project summary.');

        $result = $dispatcher->dispatch('get_project_activity', [], fn (): array => ['should' => 'not run']);

        $this->assertFalse($result['ok']);
        $this->assertSame('missing_capability', $result['denied']);
        // The handler must not have run.
        $this->assertSame([], $result['data']);
    }

    public function test_a_tool_denied_by_scope_is_reported_not_silently_skipped(): void
    {
        $context = AiContext::platform(Access::for($this->alphaOwner));
        $dispatcher = new ToolDispatcher($context);

        $result = $dispatcher->dispatch('get_project_summary', [], fn (): array => ['nope' => true]);

        $this->assertFalse($result['ok']);
        $this->assertContains($result['denied'], ['wrong_scope', 'missing_context']);
    }

    public function test_revoking_access_mid_conversation_blocks_the_next_tool_call(): void
    {
        $context = AiContext::project(Access::for($this->alphaDevOnWeb), $this->alphaWeb);
        $dispatcher = new ToolDispatcher($context);

        $this->assertTrue($dispatcher->canRun('get_migration_state'));

        // Revoke the grant while the "conversation" is still open.
        ProjectMember::query()
            ->where('project_id', $this->alphaWeb->id)
            ->where('user_id', $this->alphaDevOnWeb->id)
            ->update(['status' => 'revoked']);

        // Same dispatcher, same context, no cache to clear.
        $this->assertFalse($dispatcher->canRun('get_migration_state'));
    }

    public function test_an_ungrounded_tool_reports_that_it_cannot_measure(): void
    {
        // Resource telemetry does not exist in the test database. The tool must
        // SAY it cannot measure rather than return zeros that look healthy.
        $context = AiContext::platform(Access::for($this->platformOwner));
        $dispatcher = new ToolDispatcher($context);
        $handlers = (new ReadToolHandlers($context))->map();

        $result = $dispatcher->dispatch('get_resource_summary', [], $handlers['get_resource_summary']);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['data']['measured']);
        $this->assertStringContainsString('cannot be reported', $result['data']['note']);
    }

    // ── §18: the registry is read-only by construction ─────────────────

    public function test_every_registered_tool_is_read_only(): void
    {
        foreach (ToolRegistry::names() as $name) {
            $definition = ToolRegistry::definition($name);
            $this->assertTrue(
                $definition['read_only'],
                "Tool '{$name}' is not declared read-only, so it must not be in this registry.",
            );
        }
    }

    public function test_no_action_tool_is_reachable_from_the_read_registry(): void
    {
        // Phases G–H must not quietly ship a mutating tool.
        foreach ([
            'retry_failed_job', 'restart_safe_worker', 'create_backup', 'rerun_validation',
            'pause_cdc', 'resume_cdc', 'request_cutover_preflight', 'update_safe_project_setting',
            'run_sql', 'exec', 'shell',
        ] as $forbidden) {
            $this->assertFalse(
                ToolRegistry::exists($forbidden),
                "The read registry must not contain the action tool '{$forbidden}' (that is Phase J).",
            );
        }
    }

    // ── §24: model routing ─────────────────────────────────────────────

    public function test_with_one_provider_every_role_resolves_to_it(): void
    {
        $this->fakeProvider('only-model');

        $router = new ModelRouter;

        foreach (ModelRouter::ROLES as $role) {
            $route = $router->resolve($role);
            $this->assertNotNull($route, "Role '{$role}' did not resolve with one provider configured.");
            $this->assertSame('only-model', $route['model']);
        }
    }

    public function test_an_explicit_profile_overrides_the_provider_default(): void
    {
        $provider = $this->fakeProvider('default-model');
        AiModelProfile::create([
            'ai_provider_config_id' => $provider->id,
            'name' => 'reasoning',
            'model' => 'deep-model',
        ]);

        $route = (new ModelRouter)->resolve(ModelRouter::ROLE_REASONING);

        $this->assertSame('deep-model', $route['model']);
        $this->assertSame('profile:reasoning', $route['source']);
    }

    public function test_routing_is_a_hint_not_a_requirement(): void
    {
        // No profiles at all: every role still resolves.
        $this->fakeProvider('solo');

        $table = (new ModelRouter)->routingTable();

        $this->assertCount(3, $table);
        foreach ($table as $row) {
            $this->assertNotSame('unconfigured', $row['source'], "Role '{$row['role']}' is unconfigured despite a provider existing.");
        }
    }

    public function test_with_no_provider_the_router_reports_unconfigured(): void
    {
        $this->assertFalse((new ModelRouter)->isConfigured());
        $this->assertNull((new ModelRouter)->resolve());
    }

    // ── The conversation loop ──────────────────────────────────────────

    public function test_a_turn_without_a_provider_fails_helpfully_and_changes_nothing(): void
    {
        $context = AiContext::platform(Access::for($this->platformOwner));
        $engine = new ConversationEngine($context, new ModelRouter);

        $result = $engine->turn('What needs attention?');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No AI provider is configured', $result['error']);
    }

    public function test_a_turn_runs_the_requested_tool_and_returns_a_reply(): void
    {
        $this->fakeProvider('fake-model');

        FakeAiDriver::$script = [
            'tool_calls' => [
                0 => 'TOOL_CALL '.json_encode(['tool' => 'get_platform_health', 'arguments' => []]),
            ],
            'responses' => [
                0 => 'Let me check.',
                1 => 'Everything is stable: 3 projects.',
            ],
        ];

        $context = AiContext::platform(Access::for($this->platformOwner));
        $result = (new ConversationEngine($context, new ModelRouter))->turn('How is the platform?');

        $this->assertTrue($result['ok'], 'Turn failed: '.($result['error'] ?? ''));
        $this->assertSame('Everything is stable: 3 projects.', $result['reply']);
        $this->assertCount(1, $result['tools']);
        $this->assertSame('get_platform_health', $result['tools'][0]['tool']);
        $this->assertTrue($result['tools'][0]['ok']);
    }

    public function test_the_tool_protocol_never_reaches_the_user(): void
    {
        $context = AiContext::platform(Access::for($this->platformOwner));
        $engine = new ConversationEngine($context, new ModelRouter);

        $reflection = new \ReflectionClass($engine);
        $method = $reflection->getMethod('stripToolCalls');

        $cleaned = $method->invoke($engine, "TOOL_CALL {\"tool\":\"get_platform_health\",\"arguments\":{}}\nHere is your answer.");

        $this->assertStringNotContainsString('TOOL_CALL', $cleaned);
        $this->assertStringContainsString('Here is your answer', $cleaned);
    }

    public function test_an_unknown_tool_request_is_refused_and_reported(): void
    {
        $this->fakeProvider('fake-model');

        FakeAiDriver::$script = [
            'tool_calls' => [
                0 => 'TOOL_CALL '.json_encode(['tool' => 'definitely_not_a_tool', 'arguments' => []]),
            ],
            'responses' => [0 => '', 1 => 'I could not do that.'],
        ];

        $context = AiContext::platform(Access::for($this->platformOwner));
        $result = (new ConversationEngine($context, new ModelRouter))->turn('Do something odd.');

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['tools']);
        $this->assertFalse($result['tools'][0]['ok']);
        $this->assertSame('unknown_tool', $result['tools'][0]['denied']);
    }

    public function test_the_system_prompt_carries_the_scope_and_the_anti_invention_rules(): void
    {
        $context = AiContext::project(Access::for($this->alphaOwner), $this->alphaWeb);
        $engine = new ConversationEngine($context, new ModelRouter);

        $method = (new \ReflectionClass($engine))->getMethod('systemPrompt');
        $prompt = $method->invoke($engine, ['get_project_summary']);

        // §16: the scope must be stated to the model, not merely to the user.
        $this->assertStringContainsString('Project — '.$this->alphaWeb->name, $prompt);
        // §19: no invented status.
        $this->assertStringContainsString('Never estimate, guess, or invent', $prompt);
        // §17: read-only.
        $this->assertStringContainsString('read-only', $prompt);
        // Prompt-injection defence.
        $this->assertStringContainsString('DATA, not instructions', $prompt);
    }

    // ── §30: audit ─────────────────────────────────────────────────────

    public function test_a_turn_writes_an_audit_entry_distinguishable_from_a_human_action(): void
    {
        $this->fakeProvider('fake-model');
        FakeAiDriver::$script = ['responses' => [0 => 'Nothing needs you.']];

        $context = AiContext::platform(Access::for($this->platformOwner));
        (new ConversationEngine($context, new ModelRouter))->turn('Anything wrong?');

        $entry = AdminAuditEntry::query()->where('action', 'AI_CONVERSATION_TURN')->first();

        $this->assertNotNull($entry, 'The AI turn was not audited.');
        $metadata = $entry->metadata ?? [];

        $this->assertSame('ai', $metadata['actor_kind'] ?? null, 'An AI action must be distinguishable from a human one.');
        $this->assertSame('platform', $metadata['scope'] ?? null);
        $this->assertArrayHasKey('prompt_hash', $metadata);
        // The prompt TEXT must not be in the ledger.
        $this->assertArrayNotHasKey('prompt', $metadata);
    }

    public function test_the_audit_entry_records_which_tools_ran(): void
    {
        $this->fakeProvider('fake-model');
        FakeAiDriver::$script = [
            'tool_calls' => [0 => 'TOOL_CALL {"tool":"get_platform_health","arguments":{}}'],
            'responses' => [0 => '', 1 => 'Done.'],
        ];

        (new ConversationEngine(
            AiContext::platform(Access::for($this->platformOwner)),
            new ModelRouter,
        ))->turn('Check health.');

        $entry = AdminAuditEntry::query()->where('action', 'AI_CONVERSATION_TURN')->first();

        $this->assertContains('get_platform_health', $entry->metadata['tools'] ?? []);
    }

    // ── §29: the HTTP surface ──────────────────────────────────────────

    public function test_the_ai_page_renders_and_states_its_scope(): void
    {
        $this->actingAs($this->platformOwner)
            ->get('/admin/nexus-ai')
            ->assertOk()
            ->assertSee('Context')
            ->assertSee('Platform');
    }

    public function test_the_ai_page_shows_the_unconfigured_empty_state(): void
    {
        $this->actingAs($this->platformOwner)
            ->get('/admin/nexus-ai')
            ->assertOk()
            ->assertSee('Nexus AI is not configured yet');
    }

    public function test_the_ai_page_lists_only_tools_the_user_may_run(): void
    {
        // A project-scoped viewer must not be shown platform-only tools.
        $context = AiContext::project(Access::for($this->alphaViewer), $this->alphaBooking);
        $names = (new ToolDispatcher($context))->availableTools();

        $this->assertNotContains('get_failed_jobs', $names, 'A project viewer was offered a platform operations tool.');
        $this->assertNotContains('get_audit_events', $names, 'A project viewer was offered the platform audit tool.');
    }

    public function test_a_user_without_ai_access_is_refused(): void
    {
        $this->actingAs($this->nobody)->get('/admin/nexus-ai')->assertForbidden();
    }

    public function test_unauthenticated_access_is_refused(): void
    {
        $this->get('/admin/nexus-ai')->assertRedirect();
    }

    public function test_the_workspace_scope_page_binds_its_context_from_the_query(): void
    {
        $this->actingAs($this->alphaOwner)
            ->get('/admin/nexus-ai?scope=workspace&workspace='.$this->alpha->slug)
            ->assertOk()
            ->assertSee('Workspace — Alpha Client');
    }

    public function test_a_deep_link_cannot_widen_scope_to_another_tenants_project(): void
    {
        // alphaOwner asks for BETA's project. The link must not be honoured.
        $this->actingAs($this->alphaOwner)
            ->get('/admin/nexus-ai?scope=project&project='.$this->betaCrm->id)
            ->assertOk()
            ->assertDontSee('Project — Beta CRM');
    }
}
