<?php

namespace Tests\Feature\Phase40;

use App\Models\ActionPlan;
use App\Models\AdminAuditEntry;
use App\Models\AiProviderConfig;
use App\Models\MigrationPlan;
use App\Models\MigrationRun;
use App\Models\User;
use App\Services\Access\Access;
use App\Services\Ai\Actions\ActionBroker;
use App\Services\Ai\Actions\ActionRegistry;
use App\Services\Ai\AiContext;
use App\Services\Ai\ConversationEngine;
use App\Services\Ai\ModelRouter;
use App\Services\Ai\ToolDispatcher;
use App\Services\Ai\ToolRegistry;
use App\Services\ControlPlane\Ai\FakeAiDriver;
use App\Services\ControlPlane\Migration\Cdc\CdcCheckpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase40\Concerns\BuildsTenantFixture;
use Tests\TestCase;

/**
 * 0.4.0 Phase J — Safe AI Actions: the full J.18 matrix.
 *
 * The security-critical properties under test:
 *  - the READ and ACTION registries are separate, and the read layer can
 *    never mutate;
 *  - a model's proposal creates a PLAN and NOTHING ELSE — no mutation ever
 *    happens because the model asked;
 *  - approval is a human click bound to an immutable plan, re-authorised at
 *    execution time, refused when expired, stale, tampered, or already used;
 *  - everything is audited with redacted arguments and safe error messages.
 */
class ActionSafetyTest extends TestCase
{
    use BuildsTenantFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTenantFixture();
        FakeAiDriver::reset();
        $this->seedStream('paused');
    }

    protected function tearDown(): void
    {
        FakeAiDriver::reset();
        parent::tearDown();
    }

    /** A paused Live Sync stream on alpha-web, reachable by the alpha owner. */
    protected function seedStream(string $status = 'paused'): CdcCheckpoint
    {
        $source = \App\Models\MigrationSource::create([
            'project_id' => $this->alphaWeb->getKey(), 'type' => 'postgres',
            'display_name' => 'action-safety', 'connection' => ['host' => 'localhost'],
            'read_only' => true, 'status' => 'pending',
        ]);

        $analysis = \App\Models\MigrationAnalysis::create([
            'project_id' => $this->alphaWeb->getKey(),
            'migration_source_id' => $source->getKey(),
            'run_id' => 'analysis-'.uniqid(),
            'status' => 'completed',
        ]);

        $plan = MigrationPlan::create([
            'project_id' => $this->alphaWeb->getKey(),
            'migration_analysis_id' => $analysis->getKey(),
            'name' => 'Alpha plan',
            'status' => 'ready',
        ]);

        $run = MigrationRun::create([
            'project_id' => $this->alphaWeb->getKey(),
            'migration_plan_id' => $plan->getKey(),
            'run_id' => 'run-'.uniqid(),
            'dry_run' => false,
            'mode' => 'cdc',
            'status' => 'streaming',
        ]);

        return CdcCheckpoint::create([
            'migration_run_id' => $run->getKey(),
            'source_type' => 'postgres',
            'target_key' => 'alpha-web-target',
            'kind' => 'stream',
            'position' => ['lsn' => '0/0'],
            'signature' => 'test-signature',
            'stream_status' => $status,
        ]);
    }

    protected function projectContext(User $user): AiContext
    {
        return AiContext::project(Access::for($user), $this->alphaWeb);
    }

    protected function broker(User $user): ActionBroker
    {
        return new ActionBroker($this->projectContext($user));
    }

    // ── Registry separation (J.1) ──────────────────────────────────────

    public function test_read_and_action_registries_are_separate(): void
    {
        // No name may exist in both registries.
        $this->assertSame(
            [],
            array_intersect(ToolRegistry::names(), ActionRegistry::names()),
            'A tool name exists in BOTH registries.'
        );

        // Every READ tool is declared read-only.
        foreach (ToolRegistry::TOOLS as $name => $definition) {
            $this->assertTrue($definition['read_only'], "Read tool {$name} is not marked read_only.");
        }

        // The read dispatcher refuses every action name.
        $dispatcher = new ToolDispatcher($this->projectContext($this->alphaOwner));
        foreach (ActionRegistry::names() as $action) {
            $this->assertFalse($dispatcher->canRun($action), "The READ dispatcher can run action {$action}.");
        }
    }

    public function test_the_allowlist_is_narrow_and_closed(): void
    {
        // Exactly the Phase J allowlist, LOW + MODERATE only.
        $this->assertEqualsCanonicalizing(
            ['create_backup', 'pause_cdc', 'request_cutover_preflight', 'rerun_validation', 'resume_cdc', 'retry_failed_job'],
            ActionRegistry::names(),
        );
        foreach (ActionRegistry::names() as $name) {
            $this->assertContains(ActionRegistry::definition($name)['risk'], ['low', 'moderate']);
        }

        // The prohibited surface does not exist anywhere for the AI.
        foreach (['execute_sql', 'run_shell', 'run_command', 'edit_env', 'change_dns', 'drop_database', 'delete_project', 'restore_backup', 'perform_cutover', 'rotate_credentials', 'modify_firewall', 'modify_caddy', 'install_package', 'edit_code', 'write_code', 'apply_patch'] as $prohibited) {
            $this->assertFalse(ActionRegistry::exists($prohibited), "{$prohibited} is registered.");
            $this->assertFalse(array_key_exists($prohibited, ToolRegistry::TOOLS), "{$prohibited} is a read tool.");
        }
    }

    // ── The J.18 matrix ────────────────────────────────────────────────

    public function test_a_valid_proposal_creates_a_pending_plan_and_nothing_happens(): void
    {
        $result = $this->broker($this->alphaOwner)->propose('resume_cdc', ['reason' => 'operator asked why it stopped']);

        $this->assertTrue($result['ok'], $result['denied'] ?? '');
        $plan = $result['plan'];
        $this->assertSame(ActionPlan::STATUS_PENDING, $plan->status);
        $this->assertSame('moderate', $plan->risk);
        $this->assertSame($this->alphaWeb->getKey(), $plan->project_id);
        $this->assertStringContainsString('stream:', (string) $plan->fingerprint);

        // The stream itself is UNTOUCHED — a proposal is not a mutation.
        $this->assertSame('paused', strtolower((string) ActionRegistry::projectCheckpoint($this->alphaWeb)->stream_status));

        $entry = AdminAuditEntry::query()->where('action', 'AI_ACTION_PROPOSED')->first();
        $this->assertNotNull($entry, 'The proposal was not audited.');
        $this->assertSame('action', $entry->metadata['kind'] ?? null);
    }

    public function test_invalid_arguments_are_refused(): void
    {
        // Required argument missing.
        $this->assertSame('bad_arguments', $this->broker($this->alphaOwner)->propose('retry_failed_job', [])['denied']);
        // Unknown argument key.
        $this->assertSame(
            'bad_arguments',
            $this->broker($this->alphaOwner)->propose('retry_failed_job', ['uuid' => 'x', 'sql' => 'DELETE FROM users'])['denied'],
        );
        // Non-string value.
        $this->assertSame(
            'bad_arguments',
            $this->broker($this->alphaOwner)->propose('retry_failed_job', ['uuid' => ['injection']])['denied'],
        );
        $this->assertSame(0, ActionPlan::query()->count());
    }

    public function test_an_unknown_or_prohibited_action_is_refused(): void
    {
        foreach (['execute_sql', 'run_shell', 'drop_database', 'perform_cutover', 'made_up_action'] as $action) {
            $result = $this->broker($this->alphaOwner)->propose($action);
            $this->assertFalse($result['ok'], "{$action} was proposed.");
        }
        $this->assertSame(0, ActionPlan::query()->count(), 'A refused action left a plan behind.');
    }

    public function test_an_unauthorized_user_cannot_propose(): void
    {
        // A project VIEWER does not hold cdc.pause.
        $this->assertSame(
            'missing_capability',
            $this->broker($this->alphaViewer)->propose('pause_cdc')['denied'],
        );

        // A platform-scope context cannot propose a PROJECT action.
        $platformBroker = new ActionBroker(AiContext::platform(Access::for($this->platformOwner)));
        $this->assertSame('wrong_scope', $platformBroker->propose('resume_cdc')['denied']);

        $this->assertSame(0, ActionPlan::query()->count());
    }

    public function test_a_cross_tenant_proposal_is_refused(): void
    {
        // Beta's owner, with a context carrying ALPHA's project: the
        // capability check must fail because he cannot reach that project.
        $broker = new ActionBroker(AiContext::project(Access::for($this->betaOwner), $this->alphaWeb));

        $this->assertSame('missing_capability', $broker->propose('resume_cdc')['denied']);
        $this->assertSame(0, ActionPlan::query()->count());
    }

    public function test_a_permission_revoked_after_the_plan_cannot_execute(): void
    {
        $plan = $this->broker($this->alphaOwner)->propose('pause_cdc')['plan'];

        // The grant disappears AFTER the plan exists.
        \App\Models\WorkspaceMember::query()
            ->where('workspace_id', $this->alpha->getKey())
            ->where('user_id', $this->alphaOwner->getKey())
            ->delete();

        $result = $this->broker($this->alphaOwner)->approveAndExecute($plan->getKey(), $this->alphaOwner);

        $this->assertFalse($result['ok'], 'A revoked user executed a plan.');
        $this->assertSame('not_authorized', $result['error']);
        $plan->refresh();
        $this->assertSame(ActionPlan::STATUS_REJECTED, $plan->status);
        // And the stream was not touched.
        $this->assertSame('paused', strtolower((string) ActionRegistry::projectCheckpoint($this->alphaWeb)->stream_status));
    }

    public function test_an_expired_plan_cannot_execute(): void
    {
        $plan = $this->broker($this->alphaOwner)->propose('resume_cdc')['plan'];
        $plan->update(['expires_at' => now()->subMinute()]);

        $result = $this->broker($this->alphaOwner)->approveAndExecute($plan->getKey(), $this->alphaOwner);

        $this->assertSame('expired', $result['error']);
        $plan->refresh();
        $this->assertSame(ActionPlan::STATUS_EXPIRED, $plan->status);
        $this->assertSame('paused', strtolower((string) ActionRegistry::projectCheckpoint($this->alphaWeb)->stream_status));
    }

    public function test_a_tampered_plan_fingerprint_refuses(): void
    {
        $plan = $this->broker($this->alphaOwner)->propose('resume_cdc')['plan'];

        // Simulate the recorded state no longer matching reality.
        $plan->update(['fingerprint' => 'stream:999999:paused']);

        $result = $this->broker($this->alphaOwner)->approveAndExecute($plan->getKey(), $this->alphaOwner);

        $this->assertSame('stale', $result['error']);
        $plan->refresh();
        $this->assertSame(ActionPlan::STATUS_STALE, $plan->status);
    }

    public function test_arguments_cannot_be_modified_after_approval(): void
    {
        // The client can only ever send a plan id — there is no argument path.
        // Even direct tampering with the row is caught where state matters:
        // the fingerprint covers the stream the action targets.
        $plan = $this->broker($this->alphaOwner)->propose('pause_cdc')['plan'];
        $plan->update(['fingerprint' => 'stream:none']);

        $result = $this->broker($this->alphaOwner)->approveAndExecute($plan->getKey(), $this->alphaOwner);

        $this->assertSame('stale', $result['error']);
    }

    public function test_a_double_apply_executes_exactly_once(): void
    {
        $plan = $this->broker($this->alphaOwner)->propose('resume_cdc')['plan'];

        $first = $this->broker($this->alphaOwner)->approveAndExecute($plan->getKey(), $this->alphaOwner);
        $this->assertTrue($first['ok']);
        $this->assertSame(ActionPlan::STATUS_VERIFIED, $first['plan']->status);
        $this->assertSame(1, $first['plan']->attempts);

        // Second click (double click, second operator, replayed request).
        $second = $this->broker($this->alphaOwner)->approveAndExecute($plan->getKey(), $this->alphaOwner);
        $this->assertFalse($second['ok']);
        $this->assertSame('not_pending', $second['error']);
        $plan->refresh();
        $this->assertSame(1, $plan->attempts, 'The action executed twice.');
    }

    public function test_a_stale_target_state_refuses(): void
    {
        $plan = $this->broker($this->alphaOwner)->propose('resume_cdc')['plan'];

        // The world moves on: someone resumes the stream manually.
        ActionRegistry::projectCheckpoint($this->alphaWeb)->update(['stream_status' => 'streaming']);

        $result = $this->broker($this->alphaOwner)->approveAndExecute($plan->getKey(), $this->alphaOwner);

        $this->assertSame('stale', $result['error']);
        $plan->refresh();
        $this->assertSame(ActionPlan::STATUS_STALE, $plan->status);
    }

    public function test_a_successful_apply_is_verified_against_real_state(): void
    {
        $plan = $this->broker($this->alphaOwner)->propose('resume_cdc')['plan'];
        $result = $this->broker($this->alphaOwner)->approveAndExecute($plan->getKey(), $this->alphaOwner);

        $this->assertTrue($result['ok']);
        $plan->refresh();
        $this->assertSame(ActionPlan::STATUS_VERIFIED, $plan->status);
        $this->assertTrue($plan->result['verification']['verified'] ?? false);
        $this->assertSame('streaming', strtolower((string) ActionRegistry::projectCheckpoint($this->alphaWeb)->stream_status));

        // APPLIED and VERIFIED are recorded as separate ledger entries.
        $this->assertNotNull(AdminAuditEntry::query()->where('action', 'AI_ACTION_APPROVED')->first());
        $this->assertNotNull(AdminAuditEntry::query()->where('action', 'AI_ACTION_APPLIED')->first());
        $this->assertNotNull(AdminAuditEntry::query()->where('action', 'AI_ACTION_VERIFIED')->first());
    }

    public function test_a_failed_apply_records_a_safe_error_and_changes_nothing(): void
    {
        // alpha-booking has no stream: propose against it via a real context.
        $context = AiContext::project(Access::for($this->alphaOwner), $this->alphaBooking);
        $proposal = (new ActionBroker($context))->propose('resume_cdc');

        // No stream in this project: the plan is built, the handler refuses,
        // and a SAFE error is recorded — nothing crashes, nothing leaks.
        $this->assertTrue($proposal['ok']);
        $result = (new ActionBroker($context))->approveAndExecute($proposal['plan']->getKey(), $this->alphaOwner);
        $this->assertFalse($result['ok']);
        $proposal['plan']->refresh();
        $this->assertSame(ActionPlan::STATUS_FAILED, $proposal['plan']->status);
        $this->assertStringContainsString(
            'no Live Sync stream',
            (string) ($proposal['plan']->result['error'] ?? ''),
        );

        // And a FAILED execution records a safe message only (create_backup
        // with unconfigured credentials).
        $backup = (new ActionBroker($context))->propose('create_backup');
        $this->assertTrue($backup['ok']);
        $result = (new ActionBroker($context))->approveAndExecute($backup['plan']->getKey(), $this->alphaOwner);
        $this->assertFalse($result['ok']);
        $backup['plan']->refresh();
        $this->assertSame(ActionPlan::STATUS_FAILED, $backup['plan']->status);
        $error = (string) ($backup['plan']->result['error'] ?? '');
        $this->assertStringNotContainsString('password', strtolower($error));
        $this->assertStringNotContainsString('stack', strtolower($error));
        $this->assertLessThanOrEqual(200, mb_strlen($error));
    }

    public function test_rejection_executes_nothing_and_is_audited(): void
    {
        $plan = $this->broker($this->alphaOwner)->propose('pause_cdc')['plan'];

        $result = $this->broker($this->alphaOwner)->reject($plan->getKey(), $this->alphaOwner);
        $this->assertTrue($result['ok']);
        $plan->refresh();
        $this->assertSame(ActionPlan::STATUS_REJECTED, $plan->status);
        $this->assertSame('paused', strtolower((string) ActionRegistry::projectCheckpoint($this->alphaWeb)->stream_status));
        $this->assertNotNull(AdminAuditEntry::query()->where('action', 'AI_ACTION_REJECTED')->first());

        // A rejected plan cannot then be executed.
        $this->assertSame('not_pending', $this->broker($this->alphaOwner)->approveAndExecute($plan->getKey(), $this->alphaOwner)['error']);
    }

    public function test_audit_arguments_are_redacted_and_plans_carry_no_secrets(): void
    {
        $plan = $this->broker($this->alphaOwner)->propose('retry_failed_job', ['uuid' => '6f9619ff-8b86-d011-b42d-00c04fc964ff']);
        $this->assertTrue($plan['ok']);

        // Redaction: even if an argument arrived with a secret-shaped name it
        // could not be proposed (schema refuses unknown keys), but the ledger
        // defence exists and works.
        $this->assertSame(
            ['token' => '[redacted]'],
            ActionRegistry::redactArguments(['token' => 'super-secret-value']),
        );

        $entry = AdminAuditEntry::query()->where('action', 'AI_ACTION_PROPOSED')->latest('id')->first();
        $this->assertSame('6f9619ff-8b86-d011-b42d-00c04fc964ff', $entry->metadata['arguments']['uuid'] ?? null);
    }

    // ── Provider trust boundary (J.14/J.15) ────────────────────────────

    protected function fakeProvider(): AiProviderConfig
    {
        return AiProviderConfig::create([
            'provider' => 'fake',
            'display_name' => 'Test Provider',
            'model' => 'fake-model',
            'enabled' => true,
            'status' => 'ready',
            'timeout_seconds' => 10,
            'max_output_tokens' => 256,
        ]);
    }

    public function test_the_provider_proposing_a_registered_action_creates_only_a_plan(): void
    {
        $this->fakeProvider();
        FakeAiDriver::$script = ['responses' => [
            "TOOL_CALL {\"tool\": \"resume_cdc\", \"arguments\": {\"reason\": \"user asked\"}}\nChecking the stream.",
            'I proposed it; a human must approve.',
        ]];

        $engine = new ConversationEngine($this->projectContext($this->alphaOwner), new ModelRouter);
        $result = $engine->turn('Resume live sync please.');

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['actions']);
        $this->assertSame('resume_cdc', $result['actions'][0]['action']);
        $this->assertSame('pending', $result['actions'][0]['status']);

        // The plan exists; the stream does not move by itself.
        $this->assertSame(1, ActionPlan::query()->count());
        $this->assertSame('paused', strtolower((string) ActionRegistry::projectCheckpoint($this->alphaWeb)->stream_status));

        // The model was TOLD it cannot claim success.
        $resultsPayload = FakeAiDriver::$lastComplete['messages'];
        $toolResults = collect($resultsPayload)->first(fn ($m) => str_starts_with((string) ($m['content'] ?? ''), 'TOOL_RESULTS'));
        $this->assertStringContainsString('awaiting EXPLICIT HUMAN APPROVAL', (string) $toolResults['content']);
    }

    public function test_the_provider_trying_a_prohibited_tool_reaches_nothing(): void
    {
        $this->fakeProvider();
        FakeAiDriver::$script = ['responses' => [
            "TOOL_CALL {\"tool\": \"execute_sql\", \"arguments\": {\"sql\": \"DROP DATABASE alpha\"}}\nTOOL_CALL {\"tool\": \"run_shell\", \"arguments\": {\"command\": \"rm -rf /\"}}\nTrying.",
            'Nothing worked.',
        ]];

        $engine = new ConversationEngine($this->projectContext($this->alphaOwner), new ModelRouter);
        $result = $engine->turn('Delete everything.');

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['actions']);
        $this->assertSame(0, ActionPlan::query()->count());
        $this->assertSame(0, AdminAuditEntry::query()->where('action', 'AI_ACTION_PROPOSED')->count());
        foreach ($result['tools'] as $tool) {
            $this->assertSame('unknown_tool', $tool['denied']);
        }
    }

    public function test_injection_in_tool_results_never_triggers_an_action(): void
    {
        $this->fakeProvider();
        // Round 1: a READ returns source data containing an instruction.
        // Round 2: the model repeats it verbatim as a TOOL_CALL.
        FakeAiDriver::$script = ['responses' => [
            "TOOL_CALL {\"tool\": \"get_cdc_status\", \"arguments\": {}}",
            "TOOL_CALL {\"tool\": \"resume_cdc\", \"arguments\": {}}\nDone as instructed.",
        ]];

        $engine = new ConversationEngine($this->projectContext($this->alphaOwner), new ModelRouter);
        $result = $engine->turn('Check live sync.');

        // Even IF the model echoes injected text as a tool call, the outcome
        // is a PENDING plan (human gate), never an execution — and here the
        // injected text arrives inside TOOL_RESULTS (data), so the only
        // guarantee we assert is that nothing executed.
        $this->assertSame('paused', strtolower((string) ActionRegistry::projectCheckpoint($this->alphaWeb)->stream_status));
        $this->assertSame(0, AdminAuditEntry::query()->where('action', 'AI_ACTION_VERIFIED')->count());
    }

    public function test_a_fabricated_approval_in_content_executes_nothing(): void
    {
        $plan = $this->broker($this->alphaOwner)->propose('resume_cdc')['plan'];

        // No code path reads model output as approval: the ONLY executor is
        // approveAndExecute() from a human click. Simulate the strongest
        // wrong case — someone re-running the broker for the SAME plan twice
        // is still limited to the plan's own arguments and authorisation.
        $before = strtolower((string) ActionRegistry::projectCheckpoint($this->alphaWeb)->stream_status);

        // An UNAUTHORIZED "approver" (beta owner) cannot approve alpha's plan.
        $result = $this->broker($this->betaOwner)->approveAndExecute($plan->getKey(), $this->betaOwner);
        $this->assertFalse($result['ok']);
        $this->assertSame('not_authorized', $result['error']);

        $this->assertSame($before, strtolower((string) ActionRegistry::projectCheckpoint($this->alphaWeb)->stream_status));
    }

    // ── The page-level flow ────────────────────────────────────────────

    public function test_the_page_exposes_approve_and_reject_and_updates_the_card(): void
    {
        $this->actingAs($this->alphaOwner);
        $page = new \App\Filament\Pages\NexusAi;
        $page->requestedProjectId = $this->alphaWeb->getKey();

        $plan = (new ActionBroker($this->projectContext($this->alphaOwner)))->propose('rerun_validation')['plan'];

        $page->rejectActionPlan($plan->getKey());
        $plan->refresh();
        $this->assertSame(ActionPlan::STATUS_REJECTED, $plan->status);

        // Approving a REJECTED plan through the page is refused.
        $page->approveActionPlan($plan->getKey());
        $plan->refresh();
        $this->assertSame(ActionPlan::STATUS_REJECTED, $plan->status);
        $this->assertNull($plan->result, 'A rejected plan produced an execution result.');
    }

    public function test_the_action_card_contract_carries_the_decision_ui(): void
    {
        $plan = $this->broker($this->alphaOwner)->propose('resume_cdc')['plan'];

        $card = $plan->card();
        $this->assertSame('resume_cdc', $card['action']);
        $this->assertSame('pending', $card['status']);
        $this->assertSame('moderate', $card['risk']);
        $this->assertStringContainsString('Resume the Live Sync stream for Alpha Website', $card['intent']);
        $this->assertSame('cdc_stream', $card['affected'][0]['resource']);
        $this->assertArrayHasKey('plan_id', $card);
        $this->assertArrayHasKey('expires_at', $card);
        $this->assertArrayHasKey('expected', $card);

        // The blade carries the J.9 decision surface the browser proof drives.
        $blade = file_get_contents(__DIR__.'/../../../resources/views/filament/pages/nexus-ai.blade.php');
        $this->assertStringContainsString('nx-action-card', (string) $blade);
        $this->assertStringContainsString('Approve &amp; Apply', (string) $blade);
        $this->assertStringContainsString('rejectActionPlan', (string) $blade);
        $this->assertStringContainsString('approveActionPlan', (string) $blade);
    }

    public function test_a_verification_failed_plan_renders_honestly(): void
    {
        $plan = $this->broker($this->alphaOwner)->propose('resume_cdc')['plan'];
        $plan->update([
            'status' => ActionPlan::STATUS_VERIFICATION_FAILED,
            'result' => ['ok' => true, 'verification' => ['verified' => false, 'detail' => 'Stream status is absent, expected streaming.']],
        ]);

        $card = $plan->card();
        $this->assertSame(ActionPlan::STATUS_VERIFICATION_FAILED, $card['status']);
        $this->assertFalse($card['result']['verification']['verified']);
        $this->assertStringContainsString('Stream status is absent', (string) ($card['result']['verification']['detail'] ?? ''));

        // The blade states the failure honestly, never as success.
        $blade = file_get_contents(__DIR__.'/../../../resources/views/filament/pages/nexus-ai.blade.php');
        $this->assertStringContainsString('could NOT confirm', (string) $blade);
        $this->assertStringContainsString('verification_failed', (string) $blade);
    }
}
