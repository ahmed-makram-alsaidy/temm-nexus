<?php

namespace Tests\Feature\Phase60;

use App\Filament\Pages\NexusAi;
use App\Models\ActionPlan;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiProviderConfig;
use App\Services\Access\Access;
use App\Services\Ai\Actions\ActionBroker;
use App\Services\Ai\AiContext;
use App\Services\Ai\Scope;
use App\Services\ControlPlane\Ai\FakeAiDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Phase40\Concerns\BuildsTenantFixture;
use Tests\TestCase;

/**
 * 0.6.0 Phase F — the NEXUS AI EXPERIENCE.
 *
 * Chat-first layout (F1), context clarity (F2), the contextual welcome state
 * (F3), the tool-catalog disclosure (F4), human tool activity + grounding
 * (F5), the safe-action preview/approval UX (F6/F7), the classified provider
 * error model (F8/F9/F10), conversation persistence + history + resilience
 * (F11/F24), message hierarchy (F12), presentation formatting (F14),
 * permissions (F19), Arabic (F18) and the query bound (F21).
 *
 * The Safe AI Actions ARCHITECTURE (PLAN → EFFECT PREVIEW → HUMAN APPROVAL →
 * APPLY → VERIFY) is asserted as PRESERVED: nothing here weakens the broker.
 */
class NexusAiExperienceTest extends TestCase
{
    use BuildsTenantFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTenantFixture();
        FakeAiDriver::reset();
        $this->fakeProvider();
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

    /** The page component at PROJECT scope, as a workspace owner. */
    protected function projectPage()
    {
        return Livewire::actingAs($this->alphaOwner)
            ->test(NexusAi::class)
            ->set('requestedProjectId', $this->alphaWeb->getKey());
    }

    // ── F1 — chat first: the catalog never leads the page ──────────────

    public function test_the_chat_and_composer_render_before_the_tool_catalog(): void
    {
        $html = $this->projectPage()->assertSuccessful()->html();

        $composerAt = mb_strpos($html, 'nx-composer');
        $catalogAt = mb_strpos($html, 'data-nx-inspect="ai.tools"');

        $this->assertNotFalse($composerAt, 'The composer is missing.');
        $this->assertNotFalse($catalogAt, 'The capabilities disclosure is missing.');
        $this->assertLessThan($catalogAt, $composerAt, 'The tool catalog must come AFTER the chat surface.');

        // The audit's specimen copy is gone for good.
        $this->assertStringNotContainsString('permission-aware dispatcher', $html);
        $this->assertStringContainsString('What can Nexus AI do here?', $html);
    }

    public function test_internal_tool_names_live_only_inside_disclosures(): void
    {
        $html = $this->projectPage()->assertSuccessful()->html();

        $this->assertStringContainsString('get_project_summary', $html, 'Technical details must keep the tool inventory reachable.');

        // The default face speaks human; the tool name appears only after the
        // capabilities disclosure's nested Technical details summary.
        $catalogAt = (int) mb_strpos($html, 'data-nx-inspect="ai.tools"');
        $nameAt = (int) mb_strpos($html, 'get_project_summary');
        $techSummaryAt = (int) mb_strpos($html, 'nx-details__summary', $catalogAt);

        $this->assertGreaterThan($techSummaryAt, $nameAt, 'A raw tool name rendered on the default face of the catalog.');
    }

    // ── F2 — context clarity ───────────────────────────────────────────

    public function test_the_context_banner_speaks_product_language_in_every_scope(): void
    {
        $this->actingAs($this->alphaOwner)
            ->get('/admin/nexus-ai?scope=project&project='.$this->alphaWeb->getKey())
            ->assertOk()
            ->assertSee('Project: Alpha Website');

        $this->actingAs($this->alphaOwner)
            ->get('/admin/nexus-ai?scope=workspace&workspace='.$this->alpha->slug)
            ->assertOk()
            ->assertSee('Workspace: Alpha Client');

        $this->actingAs($this->platformOwner)
            ->get('/admin/nexus-ai')
            ->assertOk()
            ->assertSee('All accessible projects');
    }

    // ── F3 — the contextual welcome state ──────────────────────────────

    public function test_the_welcome_state_offers_prompts_for_the_current_scope_only(): void
    {
        $project = $this->projectPage()->assertSuccessful();

        $project->assertSee('Why is this migration blocked?')
            ->assertSee('What needs attention on this project?')
            ->assertDontSee('Which projects need attention?');

        $platform = Livewire::actingAs($this->platformOwner)->test(NexusAi::class);
        $platform->assertSuccessful()
            ->assertSee('Which projects need attention?')
            ->assertSee('Summarize recent migration activity.')
            ->assertDontSee('Why is this migration blocked?');
    }

    // ── F9 — provider not configured ───────────────────────────────────

    public function test_unconfigured_provider_shows_a_recovering_state_not_a_dead_end(): void
    {
        AiProviderConfig::query()->delete();

        // A normal user: what to do, no write controls.
        $this->actingAs($this->alphaOwner)
            ->get('/admin/nexus-ai')
            ->assertOk()
            ->assertSee('Nexus AI is not configured yet.')
            ->assertSee('Ask an administrator to connect an AI provider.')
            ->assertDontSee('Set up AI')
            ->assertDontSee('nx-composer');

        // An administrator: the CTA to the canonical settings destination.
        $this->actingAs($this->platformOwner)
            ->get('/admin/nexus-ai')
            ->assertOk()
            ->assertSee('Set up AI')
            ->assertSee('/admin/nexus-ai-settings', false);
    }

    // ── F8/F10 — the classified provider error model ───────────────────

    public function test_an_authentication_failure_renders_the_safe_recoverable_card(): void
    {
        FakeAiDriver::$script = ['throw' => ['code' => 401, 'message' => 'Provider authentication failed (HTTP 401).']];

        $page = $this->projectPage();
        $page->set('message', 'Why is this migration blocked?')->call('send');

        $page->assertSuccessful()
            ->assertSee('The AI provider rejected the credentials.')
            ->assertSee('retryFailedTurn', false)
            // The question survives the failure — never a dangling turn.
            ->assertSee('Why is this migration blocked?');

        $html = $page->html();
        $this->assertStringContainsString('HTTP 401', $html, 'Technical details must keep the status.');

        // The raw classification never leads the default face: its LAST
        // occurrence is inside the Technical details disclosure that follows
        // the error title (the wire snapshot embeds an earlier copy).
        $titleAt = (int) mb_strpos($html, 'The AI provider rejected the credentials.');
        $techAt = (int) mb_strrpos($html, 'HTTP 401');
        $summaryAt = (int) mb_strpos($html, 'nx-details__summary', $titleAt);
        $this->assertGreaterThan($summaryAt, $techAt, 'Raw provider text rendered outside the Technical details disclosure.');

        // No assistant bubble for a failed turn.
        $this->assertSame(1, AiMessage::query()->where('role', 'user')->count());
        $this->assertSame(0, AiMessage::query()->where('role', 'assistant')->count());
    }

    public function test_provider_unavailable_is_its_own_product_state(): void
    {
        FakeAiDriver::$script = ['throw' => ['code' => 503, 'message' => 'Upstream unavailable.']];

        $page = $this->projectPage();
        $page->set('message', 'Hello?')->call('send');

        $page->assertSuccessful()
            ->assertSee('The AI provider is temporarily unavailable.')
            ->assertSee('HTTP 503', false);
    }

    public function test_rate_limiting_is_its_own_product_state(): void
    {
        FakeAiDriver::$script = ['throw' => ['code' => 429, 'message' => 'Too many requests.']];

        $page = $this->projectPage();
        $page->set('message', 'Hello?')->call('send');

        $page->assertSuccessful()
            ->assertSee('The AI provider is rate limiting requests.')
            ->assertSee('HTTP 429', false);
    }

    public function test_model_unavailable_is_its_own_product_state(): void
    {
        FakeAiDriver::$script = ['throw' => ['code' => 404, 'message' => 'Model not found.']];

        $page = $this->projectPage();
        $page->set('message', 'Hello?')->call('send');

        $page->assertSuccessful()
            ->assertSee('The configured model is not available on the provider.')
            ->assertSee('HTTP 404', false);
    }

    public function test_an_unreadable_provider_response_is_its_own_product_state(): void
    {
        FakeAiDriver::$script = ['throw' => ['code' => 200, 'message' => 'The provider returned an error envelope.']];

        $page = $this->projectPage();
        $page->set('message', 'Hello?')->call('send');

        $page->assertSuccessful()
            ->assertSee('returned a response we couldn\'t read');
    }

    public function test_an_unexpected_failure_stays_honest_and_safe(): void
    {
        FakeAiDriver::$script = ['throw' => ['type' => 'generic', 'message' => 'boom']];

        $page = $this->projectPage();
        $page->set('message', 'Hello?')->call('send');

        $page->assertSuccessful()
            ->assertSee('The assistant could not complete that request.')
            ->assertSee('Nothing was changed.');
    }

    // ── F24 — no blank success bubble ──────────────────────────────────

    public function test_an_empty_provider_reply_never_becomes_a_blank_bubble(): void
    {
        FakeAiDriver::$script = ['responses' => [0 => '   ']];

        $page = $this->projectPage();
        $page->set('message', 'Hello?')->call('send');

        $page->assertSuccessful()->assertSee('The AI provider returned an empty response.');

        $this->assertSame(1, AiMessage::query()->where('role', 'user')->count());
        $this->assertSame(0, AiMessage::query()->where('role', 'assistant')->count());
    }

    // ── F24 — retry never duplicates the user message ──────────────────

    public function test_retry_reuses_the_failed_turn_without_duplicating_it(): void
    {
        FakeAiDriver::$script = ['throw' => ['code' => 503, 'message' => 'down']];
        $page = $this->projectPage();
        $page->set('message', 'Is the migration okay?')->call('send');
        $this->assertSame(1, AiMessage::query()->count());

        // Recover: the next engine call succeeds (the scripted driver's call
        // counter resets with the script).
        FakeAiDriver::reset();
        FakeAiDriver::$script = ['responses' => [0 => 'Everything is on track.']];
        $page->call('retryFailedTurn');

        $page->assertSuccessful()->assertSee('Everything is on track.');

        $rows = AiMessage::query()->orderBy('created_at')->get();
        $this->assertCount(2, $rows, 'Retry duplicated the turn.');
        $this->assertSame('user', $rows[0]->role);
        $this->assertSame('Is the migration okay?', $rows[0]->text);
        $this->assertNull($rows[0]->error, 'A recovered turn kept its failure marker.');
        $this->assertSame('assistant', $rows[1]->role);
        $this->assertNull($page->instance()->error, 'A recovered turn left its error card behind.');
    }

    // ── F11/F24 — persistence: refresh never loses the conversation ────

    public function test_a_conversation_persists_and_survives_a_full_reload(): void
    {
        FakeAiDriver::$script = ['responses' => [0 => 'Two blockers remain in Verify.']];
        $page = $this->projectPage();
        $page->set('message', 'What is blocking the migration?')->call('send');

        $conversation = AiConversation::query()->first();
        $this->assertNotNull($conversation, 'The conversation was not persisted.');
        $this->assertSame('project', $conversation->scope);
        $this->assertSame($this->alphaWeb->getKey(), $conversation->project_id);
        $this->assertSame($this->alphaOwner->getKey(), $conversation->user_id);
        $this->assertSame('What is blocking the migration?', $conversation->title);
        $this->assertSame(2, $conversation->messages()->count());

        // A fresh page load (the "refresh") restores the thread.
        $this->actingAs($this->alphaOwner)
            ->get('/admin/nexus-ai?scope=project&project='.$this->alphaWeb->getKey())
            ->assertOk()
            ->assertSee('What is blocking the migration?')
            ->assertSee('Two blockers remain in Verify.');
    }

    public function test_a_failed_turn_persists_as_honest_history_with_retry(): void
    {
        FakeAiDriver::$script = ['throw' => ['code' => 401, 'message' => 'auth failed']];
        $page = $this->projectPage();
        $page->set('message', 'Will this survive a refresh?')->call('send');

        $row = AiMessage::query()->where('role', 'user')->first();
        $this->assertSame('auth', $row->error['kind']);

        // The refreshed page shows the question AND the honest failure state.
        $this->actingAs($this->alphaOwner)
            ->get('/admin/nexus-ai?scope=project&project='.$this->alphaWeb->getKey())
            ->assertOk()
            ->assertSee('Will this survive a refresh?')
            ->assertSee('The AI provider rejected the credentials.')
            ->assertSee('retryFailedTurn', false);
    }

    public function test_conversation_titles_are_human_and_bounded(): void
    {
        FakeAiDriver::$script = ['responses' => [0 => 'Ok.']];
        $page = $this->projectPage();
        $long = 'This is a very long question that should be trimmed for the history list because sixty characters arrive quickly';
        $page->set('message', $long)->call('send');

        $title = AiConversation::query()->first()->title;
        $this->assertLessThanOrEqual(63, mb_strlen($title));
        $this->assertStringStartsWith('This is a very long question', $title);
    }

    // ── F11 — history: bounded, searchable, context-scoped ─────────────

    public function test_the_history_list_is_bounded_searchable_and_context_scoped(): void
    {
        // 10 threads in the project context, 2 elsewhere.
        for ($i = 1; $i <= 10; $i++) {
            $conversation = AiConversation::query()->create([
                'user_id' => $this->alphaOwner->getKey(),
                'scope' => Scope::PROJECT->value,
                'workspace_id' => $this->alpha->getKey(),
                'project_id' => $this->alphaWeb->getKey(),
                'title' => ($i % 2 === 0 ? 'Cutover question '.$i : 'Migration question '.$i),
                'last_active_at' => now()->subMinutes($i),
            ]);
            AiMessage::query()->create([
                'ai_conversation_id' => $conversation->getKey(),
                'role' => 'user',
                'text' => $conversation->title,
            ]);
        }
        $otherProject = AiConversation::query()->create([
            'user_id' => $this->alphaOwner->getKey(),
            'scope' => Scope::PROJECT->value,
            'workspace_id' => $this->alpha->getKey(),
            'project_id' => $this->alphaBooking->getKey(),
            'title' => 'Migration question elsewhere',
            'last_active_at' => now(),
        ]);
        AiMessage::query()->create(['ai_conversation_id' => $otherProject->getKey(), 'role' => 'user', 'text' => 'x']);
        $beta = AiConversation::query()->create([
            'user_id' => $this->betaOwner->getKey(),
            'scope' => Scope::PROJECT->value,
            'workspace_id' => $this->beta->getKey(),
            'project_id' => $this->betaCrm->getKey(),
            'title' => 'Beta private thread',
            'last_active_at' => now(),
        ]);
        AiMessage::query()->create(['ai_conversation_id' => $beta->getKey(), 'role' => 'user', 'text' => 'x']);

        $page = $this->projectPage()->call('toggleHistory');
        $history = $page->instance()->conversations();

        $this->assertCount(8, $history['rows'], 'The history page size is not bounded.');
        $this->assertTrue($history['has_more']);
        // Only THIS context's threads, newest first; Beta's threads never appear.
        $titles = array_column($history['rows'], 'title');
        $this->assertNotContains('Beta private thread', $titles);
        $this->assertNotContains('Migration question elsewhere', $titles);

        // Search narrows within the same bound.
        $page->set('historySearch', 'Cutover');
        $history = $page->instance()->conversations();
        $this->assertSame([], array_diff(array_column($history['rows'], 'title'), [
            'Cutover question 2', 'Cutover question 4', 'Cutover question 6', 'Cutover question 8', 'Cutover question 10',
        ]));
        $this->assertFalse($history['has_more']);
    }

    public function test_opening_a_history_thread_loads_it_and_never_crosses_tenants(): void
    {
        $mine = AiConversation::query()->create([
            'user_id' => $this->alphaOwner->getKey(),
            'scope' => Scope::PROJECT->value,
            'workspace_id' => $this->alpha->getKey(),
            'project_id' => $this->alphaWeb->getKey(),
            'title' => 'My thread',
            'last_active_at' => now(),
        ]);
        AiMessage::query()->create(['ai_conversation_id' => $mine->getKey(), 'role' => 'user', 'text' => 'My old question']);
        AiMessage::query()->create(['ai_conversation_id' => $mine->getKey(), 'role' => 'assistant', 'text' => 'My old answer']);

        $beta = AiConversation::query()->create([
            'user_id' => $this->betaOwner->getKey(),
            'scope' => Scope::PROJECT->value,
            'workspace_id' => $this->beta->getKey(),
            'project_id' => $this->betaCrm->getKey(),
            'title' => 'Beta thread',
            'last_active_at' => now(),
        ]);

        $page = $this->projectPage();
        $page->call('openConversation', $mine->getKey());
        $this->assertSame('My old question', $page->instance()->transcript[0]['text']);
        $this->assertSame('My old answer', $page->instance()->transcript[1]['text']);

        // Beta's thread id does not resolve in this user + context.
        $page->call('openConversation', $beta->getKey());
        $this->assertSame('My thread', AiConversation::find($page->instance()->activeConversationId)->title);
    }

    // ── F5/F15 — human tool activity + grounding, no raw JSON ─────────

    public function test_tool_activity_is_human_and_grounded_and_leaks_nothing(): void
    {
        FakeAiDriver::$script = [
            'tool_calls' => [0 => 'TOOL_CALL {"tool":"get_migration_state","arguments":{}}'],
            'responses' => [0 => 'Let me check.', 1 => "The migration is in **Verify**, 2 blockers remain.\n\n- Blocker A\n- Blocker B"],
        ];

        $page = $this->projectPage();
        $page->set('message', 'Where is the migration?')->call('send');

        $page->assertSuccessful()
            ->assertSee('Checking the migration state…')
            ->assertSee('Based on:')
            ->assertSee('The migration is in');

        $html = $page->html();
        $this->assertStringNotContainsString('TOOL_CALL', $html, 'The tool protocol leaked into the transcript.');
        $this->assertStringNotContainsString('TOOL_RESULTS', $html);
        $this->assertStringNotContainsString('&quot;tool&quot;:', $html, 'Raw tool JSON leaked into the transcript.');

        // §F14 presentation: markdown became structure, without changing words.
        $this->assertStringContainsString('<strong>Verify</strong>', $html);
        $this->assertStringContainsString('<ul>', $html);
    }

    public function test_raw_html_in_a_model_reply_is_never_rendered(): void
    {
        FakeAiDriver::$script = ['responses' => [0 => 'Safe text <script>alert(1)</script> stays text.']];

        $page = $this->projectPage();
        $page->set('message', 'Hi')->call('send');

        $html = $page->html();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('Safe text', $html);
    }

    public function test_a_denied_tool_reads_as_a_human_reason_not_an_internal_name(): void
    {
        // A viewer may not run platform-only tools; the model tries anyway.
        FakeAiDriver::$script = [
            'tool_calls' => [0 => 'TOOL_CALL {"tool":"get_failed_jobs","arguments":{}}'],
            'responses' => [0 => '', 1 => 'I could not check that.'],
        ];

        $context = AiContext::project(Access::for($this->alphaViewer), $this->alphaBooking);
        $page = Livewire::actingAs($this->alphaViewer)->test(NexusAi::class)
            ->set('requestedProjectId', $this->alphaBooking->getKey());
        $page->set('message', 'Any failed jobs?')->call('send');

        $page->assertSuccessful()
            ->assertSee('not permitted for your role')
            // The refusal shows the HUMAN label, not `get_failed_jobs`…
            ->assertSee('Checking failed jobs');

        // …and the internal name never renders on the default face of the turn.
        $html = $page->html();
        $turnAt = (int) mb_strpos($html, 'nx-chat__turn');
        $this->assertStringNotContainsString('get_failed_jobs', mb_substr($html, 0, (int) mb_strrpos($html, 'nx-chat__tools')), 'Denied tool internal name rendered before the turn ends.');
    }

    // ── F6/F7 — safe-action preview + approval UX, architecture intact ─

    public function test_a_proposed_action_renders_a_effect_preview_card_with_approve_and_reject(): void
    {
        FakeAiDriver::$script = [
            'tool_calls' => [0 => 'TOOL_CALL {"tool":"rerun_validation","arguments":{}}'],
            'responses' => [0 => 'I propose re-running validation.', 1 => 'The plan is awaiting your approval.'],
        ];

        $page = $this->projectPage();
        $page->set('message', 'Please re-check readiness.')->call('send');

        $page->assertSuccessful()
            ->assertSee('Proposed action — Re-run validation')
            ->assertSee('Re-run the readiness validation checks for Alpha Website.')
            ->assertSee('Re-runs verification checks. No source data will be modified.')
            ->assertSee('Low risk')
            ->assertSee(__('ai.approve'))
            ->assertSee(__('ai.reject'));

        $plan = ActionPlan::query()->sole();
        $this->assertSame(ActionPlan::STATUS_PENDING, $plan->status, 'A proposal executed something.');

        // The internal plan id lives in Technical details, not the default face.
        // (The LAST occurrence is the rendered one — the wire snapshot embeds
        // an earlier copy of the transcript JSON.)
        $html = $page->html();
        $summaryAt = (int) mb_strpos($html, 'nx-action-card__tech');
        $planIdAt = (int) mb_strrpos($html, $plan->getKey());
        $this->assertGreaterThan($summaryAt, $planIdAt, 'The plan id rendered on the default face.');
    }

    public function test_rejecting_a_proposed_action_executes_nothing_and_updates_the_card(): void
    {
        $plan = (new ActionBroker(AiContext::project(Access::for($this->alphaOwner), $this->alphaWeb)))
            ->propose('rerun_validation')['plan'];

        $page = $this->projectPage();
        $page->call('rejectActionPlan', $plan->getKey());

        $plan->refresh();
        $this->assertSame(ActionPlan::STATUS_REJECTED, $plan->status);
        $page->assertSuccessful()->assertSet('transcript', []);
    }

    public function test_approving_a_proposed_action_runs_the_registered_pipeline_and_persists_the_result(): void
    {
        // A real streaming checkpoint, so resume_cdc's execute+verify
        // pipeline (PLAN → APPLY → VERIFY) can genuinely pass.
        $this->seedStreamingCheckpoint();
        $plan = (new ActionBroker(AiContext::project(Access::for($this->alphaOwner), $this->alphaWeb)))
            ->propose('resume_cdc')['plan'];

        // The proposal lives inside a persisted assistant turn (the F24 rule:
        // an approved result must survive a refresh).
        $conversation = AiConversation::query()->create([
            'user_id' => $this->alphaOwner->getKey(),
            'scope' => Scope::PROJECT->value,
            'workspace_id' => $this->alpha->getKey(),
            'project_id' => $this->alphaWeb->getKey(),
            'title' => 'Live Sync',
            'last_active_at' => now(),
        ]);
        AiMessage::query()->create([
            'ai_conversation_id' => $conversation->getKey(),
            'role' => 'assistant',
            'text' => 'I can resume the stream — approval needed.',
            'actions' => [$plan->card()],
        ]);

        $page = $this->projectPage();
        $page->call('openConversation', $conversation->getKey());
        $page->call('approveActionPlan', $plan->getKey());

        $plan->refresh();
        $this->assertSame(ActionPlan::STATUS_VERIFIED, $plan->status, 'The registered PLAN→APPLY→VERIFY pipeline changed behaviour.');

        $row = AiMessage::query()->where('ai_conversation_id', $conversation->getKey())
            ->where('role', 'assistant')->first();
        $this->assertSame('verified', $row->actions[0]['status'], 'The approved result did not persist to the conversation.');

        $page->assertSuccessful()->assertSee('Applied and verified');
    }

    // ── F19 — a user who cannot approve never sees an enabled Approve ──

    public function test_a_chat_user_without_approval_permission_sees_a_disabled_approve(): void
    {
        $plan = (new ActionBroker(AiContext::project(Access::for($this->alphaOwner), $this->alphaWeb)))
            ->propose('rerun_validation')['plan'];

        // The developer can chat on this project but holds no AI approve capability.
        $page = Livewire::actingAs($this->alphaDevOnWeb)->test(NexusAi::class)
            ->set('requestedProjectId', $this->alphaWeb->getKey());

        $this->assertFalse($page->instance()->canApproveCard($plan->card()));

        $page = Livewire::actingAs($this->alphaDevOnWeb)->test(NexusAi::class)
            ->set('requestedProjectId', $this->alphaWeb->getKey())
            ->set('transcript', [['role' => 'assistant', 'text' => 'Proposal ready.', 'tools' => [], 'actions' => [$plan->card()]]]);

        $html = $page->assertSuccessful()->html();
        $disabledAt = mb_strpos($html, 'disabled');
        $approveAt = mb_strpos($html, 'ai.approve');
        $reasonAt = mb_strpos($html, 'Your role can review this, but approving it needs an additional permission.');

        $this->assertNotFalse($reasonAt, 'The disabled Approve control carried no reason.');
        $this->assertNotFalse($disabledAt);

        // Server-side: the broker refuses this user anyway (the UI is not the boundary).
        $page->call('approveActionPlan', $plan->getKey());
        $plan->refresh();
        $this->assertSame(ActionPlan::STATUS_REJECTED, $plan->status);
    }

    // ── F16 — Inspect Mode still feeds the redesigned chat ─────────────

    public function test_inspect_mode_context_flows_into_a_turn_and_the_page_keeps_its_inspect_surfaces(): void
    {
        // ai.* registry components resolve at PLATFORM scope (the same rule
        // the Phase I tests pin), so this journey runs from the platform.
        FakeAiDriver::$script = ['responses' => [0 => 'That card lists your projects.']];

        $page = Livewire::actingAs($this->platformOwner)->test(NexusAi::class);
        $page->call('attachComponent', 'home.continue');
        $page->set('message', 'What is this?')->call('send');

        $page->assertSuccessful()
            ->assertSee('Selected component')
            ->assertSee('That card lists your projects.');

        $html = $page->html();
        foreach (['data-nx-attach-root', 'data-nx-inspect="ai.transcript"', 'data-nx-inspect="ai.tools"', 'data-nx-inspect="ai.scope_banner"'] as $marker) {
            $this->assertStringContainsString($marker, $html, "The Inspect surface {$marker} disappeared from the redesigned page.");
        }
    }

    // ── F18 — Arabic: the chat is a first-class RTL surface ────────────

    public function test_the_chat_renders_translated_in_arabic_with_ltr_technical_values(): void
    {
        // §E25 — the user's language preference drives the render (the same
        // resolution the topbar locale switcher writes).
        $this->alphaOwner->update(['locale' => 'ar']);
        app()->setLocale('ar');

        $this->actingAs($this->alphaOwner)
            ->get('/admin/nexus-ai?scope=project&project='.$this->alphaWeb->getKey())
            ->assertOk()
            ->assertSee('المشروع: Alpha Website')
            ->assertSee('من أين تحب أن نبدأ؟')
            ->assertSee('لماذا هذا الترحيل متوقف؟')
            ->assertSee('ما الذي يستطيع Nexus AI فعله هنا؟');
    }

    public function test_provider_errors_speak_arabic_on_the_default_face(): void
    {
        $this->alphaOwner->update(['locale' => 'ar']);
        app()->setLocale('ar');
        FakeAiDriver::$script = ['throw' => ['code' => 401, 'message' => 'unauthorized']];

        $page = Livewire::actingAs($this->alphaOwner)->test(NexusAi::class)
            ->set('requestedProjectId', $this->alphaWeb->getKey());
        $page->set('message', 'مرحبًا')->call('send');

        $page->assertSuccessful()
            ->assertSee('رفض مزوّد الذكاء الاصطناعي بيانات الاعتماد.')
            // Technical values stay LTR inside the Arabic page.
            ->assertSee('HTTP 401', false);
    }

    // ── F19 — role density differences ─────────────────────────────────

    public function test_a_viewer_can_chat_but_sees_no_configuration_or_approval_controls(): void
    {
        $this->actingAs($this->alphaViewer)
            ->get('/admin/nexus-ai?scope=project&project='.$this->alphaBooking->getKey())
            ->assertOk()
            ->assertSee('nx-composer', false)
            ->assertDontSee('Set up AI');
    }

    // ── F21 — the render stays bounded with history volume ─────────────

    public function test_the_page_render_stays_bounded_with_a_large_history(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $conversation = AiConversation::query()->create([
                'user_id' => $this->alphaOwner->getKey(),
                'scope' => Scope::PROJECT->value,
                'workspace_id' => $this->alpha->getKey(),
                'project_id' => $this->alphaWeb->getKey(),
                'title' => 'Thread '.$i,
                'last_active_at' => now()->subMinutes($i),
            ]);
            for ($j = 0; $j < 5; $j++) {
                AiMessage::query()->create([
                    'ai_conversation_id' => $conversation->getKey(),
                    'role' => $j % 2 === 0 ? 'user' : 'assistant',
                    'text' => "Turn {$j} of thread {$i}",
                ]);
            }
        }

        DB::enableQueryLog();
        $this->actingAs($this->alphaOwner)
            ->get('/admin/nexus-ai?scope=project&project='.$this->alphaWeb->getKey())
            ->assertOk();
        $log = collect(DB::getQueryLog());
        $mountQueries = $log->count();
        if (getenv('NX_DEBUG_QUERIES')) {
            $log->groupBy(fn ($q) => preg_replace('/\?.*/', '?', preg_replace("/'[^']*'/", '?', $q['query'])))
                ->map->count()
                ->sortDesc()
                ->take(10)
                ->each(fn ($count, $sql) => dump($count.' × '.substr($sql, 0, 130)));
        }
        DB::disableQueryLog();

        // 150 messages exist; the mount loads ONE conversation (≤ 100 rows)
        // and never touches the whole history (it is closed by default).
        // Composition, measured: ~57 of these are Access::allows()'s BY-DESIGN
        // membership re-reads (capability checks re-read per call — the
        // dispatcher's "no cache to clear" contract), ~10 the model router's
        // role resolution, ~10 the Filament shell + auth; the conversation
        // tables themselves are touched exactly twice (one latestFor lookup,
        // one bounded ≤100-row message load) regardless of volume.
        $this->assertLessThan(100, $mountQueries, 'Mounting the page with a large history ran '.$mountQueries.' queries.');

        // Opening the history panel adds a bounded page, not the full table.
        DB::enableQueryLog();
        $page = $this->projectPage()->call('toggleHistory');
        $page->instance()->conversations();
        DB::disableQueryLog();
        $this->assertCount(8, $page->instance()->conversations()['rows']);
    }

    /** A real CDC chain (source → analysis → plan → run → checkpoint) for the approve journey. */
    protected function seedStreamingCheckpoint(): void
    {
        $source = \App\Models\MigrationSource::create([
            'project_id' => $this->alphaWeb->getKey(), 'type' => 'postgres',
            'display_name' => 'phase-f-approve', 'connection' => ['host' => 'localhost'],
            'read_only' => true, 'status' => 'pending',
        ]);
        $analysis = \App\Models\MigrationAnalysis::create([
            'project_id' => $this->alphaWeb->getKey(),
            'migration_source_id' => $source->getKey(),
            'run_id' => 'analysis-'.uniqid(),
            'status' => 'completed',
        ]);
        $plan = \App\Models\MigrationPlan::create([
            'project_id' => $this->alphaWeb->getKey(),
            'migration_analysis_id' => $analysis->getKey(),
            'name' => 'Alpha plan',
            'status' => 'ready',
        ]);
        $run = \App\Models\MigrationRun::create([
            'project_id' => $this->alphaWeb->getKey(),
            'migration_plan_id' => $plan->getKey(),
            'run_id' => 'run-'.uniqid(),
            'dry_run' => false,
            'mode' => 'cdc',
            'status' => 'streaming',
        ]);
        \App\Services\ControlPlane\Migration\Cdc\CdcCheckpoint::create([
            'migration_run_id' => $run->getKey(),
            'source_type' => 'postgres',
            'target_key' => 'alpha-web-target',
            'kind' => 'stream',
            'position' => ['lsn' => '0/0'],
            'signature' => 'test-signature',
            'stream_status' => 'streaming',
        ]);
    }

    /** A non-rendering helper: the page instance behind the current test. */
    protected function projectPageInstance(): NexusAi
    {
        return Livewire::actingAs($this->alphaOwner)->test(NexusAi::class)
            ->set('requestedProjectId', $this->alphaWeb->getKey())
            ->instance();
    }
}
