<?php

namespace Tests\Feature\Phase60;

use App\Models\AgentApproval;
use App\Models\AgentChangeset;
use App\Models\AgentRuntime;
use App\Models\AgentTask;
use App\Models\AgentTaskEvent;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\Access\Roles;
use App\Services\Agent\AgentStatusPresenter;
use App\Services\Agent\AgentTaskService;
use App\Services\Agent\AgentWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Phase43\Concerns\BuildsAgentFixtureProject;
use Tests\Feature\Phase43\Concerns\RunsMockOpenCode;
use Tests\TestCase;

/**
 * 0.6.0 Phase G — the DEVELOPER AGENT EXPERIENCE.
 *
 * Status-first workbench (G1), simple task creation (G2), runtime recovery
 * (G3), quiet runtime state (G4), the human status dictionary (G5), the
 * humanized activity timeline (G6), the decision-oriented diff (G7/G8),
 * the tests section (G9), the ready-for-review decision card (G10–G12),
 * the apply lifecycle (G13), failure/cancel UX (G14/G17/G18/G19), task
 * history (G20), empty state (G21), cross-interface state (G22), model
 * discovery without invented labels (G23/G24), permissions (G25), Arabic
 * (G26), error migration (G27), query bounds (G28) and the Inspect registry
 * (G30).
 *
 * The SECURITY MODEL is asserted as PRESERVED: every mutation still goes
 * through AgentTaskService authorization, the fingerprint semantics are
 * untouched, and approval/apply/cancel/reject remain audited.
 */
class DeveloperAgentExperienceTest extends TestCase
{
    use RefreshDatabase, BuildsAgentFixtureProject, RunsMockOpenCode;

    protected function tearDown(): void
    {
        $this->tearDownMock();
        parent::tearDown();
    }

    protected function mockRuntime(string $baseUrl): AgentRuntime
    {
        return AgentRuntime::create([
            'driver' => 'opencode',
            'display_name' => 'Mock OpenCode',
            'mode' => AgentRuntime::MODE_EXTERNAL,
            'endpoint' => $baseUrl,
            'auth_secret_encrypted' => 'mock-password',
            'enabled' => true,
            'status' => AgentRuntime::STATUS_CONNECTED,
            'version' => '1.18.34',
            'timeout_seconds' => 120,
            'max_concurrent_tasks' => 2,
            'workspace_retention_days' => 1,
        ]);
    }

    /** Run the happy-path task synchronously (queue is sync in tests). */
    protected function runHappyTask(User $owner): array
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('happy');
        [$project, $repoPath] = $this->agentFixtureProject($owner);
        config(['agent.verification.projects' => [$project->slug => ['"'.PHP_BINARY.'" -r "exit(0);"', 'echo ok']]]);

        $runtime = $this->mockRuntime($baseUrl);
        $task = app(AgentTaskService::class)->createTask(
            $owner, $project, $runtime, 'mockprovider/mock-small',
            'Update the greeting to HELLO TEMM.', 'Update the greeting',
        );
        $task->refresh();
        $this->assertSame(
            AgentTask::STATUS_AWAITING_APPROVAL,
            $task->status,
            'The happy-path task did not reach review. Status: '.$task->status
                .', error: '.($task->error_category ?? 'none'),
        );

        return [$task, $project, $runtime, $repoPath, $baseUrl];
    }

    protected function cleanup(string $repoPath): void
    {
        AgentWorkspaceService::deleteTree(AgentWorkspaceService::root());
        $this->cleanupFixtureRepo($repoPath);
    }

    // ── G3/G4 — runtime recovery + quiet runtime state ──────────────────

    public function test_runtime_not_configured_shows_recovery_with_admin_cta(): void
    {
        $owner = $this->agentOwner();
        $this->actingAs($owner);

        $page = Livewire::test(\App\Filament\Pages\DeveloperAgent::class);

        $page->assertSuccessful()
            ->assertSee('Developer Agent isn\'t configured yet.')
            ->assertSee('Set up a runtime')
            ->assertSee('/admin/developer-agent-settings', false)
            // No composer-style dead end: the creation form is absent.
            ->assertDontSee('Start a task');
    }

    public function test_runtime_not_configured_shows_no_admin_cta_for_non_configurers(): void
    {
        // A project-only viewer cannot open the platform workbench (403) —
        // assert the honest refusal plus the capability facts (§G3/§G25).
        $owner = $this->agentOwner();
        [$project, $repoPath] = $this->agentFixtureProject($owner);

        $viewer = User::factory()->create(['is_admin' => false, 'cp_role' => null]);
        ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $viewer->id,
            'role' => Roles::VIEWER, 'status' => 'active', 'granted_at' => now(),
        ]);

        $this->actingAs($viewer)->get('/admin/developer-agent')->assertForbidden();
        $this->assertFalse(\App\Filament\Support\PlatformAccess::current()->allowsPlatform(\App\Services\Access\Capability::AGENTS_CONFIGURE), 'A project viewer could configure runtimes.');

        $this->cleanup($repoPath);
    }

    public function test_runtime_state_is_quiet_and_never_leaks_internals(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('happy');
        $owner = $this->agentOwner();
        $this->actingAs($owner);
        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $this->mockRuntime($baseUrl);

        $html = Livewire::test(\App\Filament\Pages\DeveloperAgent::class)->assertSuccessful()->html();

        $this->assertStringContainsString('Runtime connected', $html);
        $this->assertStringContainsString('OpenCode 1.18.34', $html, 'The detected version is shown quietly.');
        // Internal endpoint, basic-auth material and runtime uuid never render.
        $this->assertStringNotContainsString((string) parse_url($baseUrl, PHP_URL_HOST).':'.parse_url($baseUrl, PHP_URL_PORT), $html);
        $this->assertStringNotContainsString('mock-password', $html);

        $this->cleanup($repoPath);
    }

    // ── G2 — task creation: simple, no internals ────────────────────────

    public function test_task_creation_is_simple_and_starts_the_real_lifecycle(): void
    {
        [$task, $project, $runtime, $repoPath, $baseUrl] = $this->runHappyTask($this->agentOwner());
        $owner = $task->createdBy;
        $this->actingAs($owner);

        $page = Livewire::test(\App\Filament\Pages\DeveloperAgent::class)
            ->set('taskForm.project_id', $project->id)
            ->set('taskForm.runtime_id', $runtime->id)
            ->set('taskForm.prompt', 'Update the README badge.')
            ->set('taskForm.title', 'README badge');

        $html = $page->assertSuccessful()->html();
        // §G2: project + task + model only; no runtime identity, session ids,
        // workspace paths or execution policy internals on the form.
        $formSlice = mb_substr($html, 0, (int) mb_strpos($html, 'agent.tasks'));
        $this->assertStringContainsString('agent-task-prompt', (string) $formSlice);
        $this->assertStringNotContainsString($runtime->getKey(), $this->stripSnapshot((string) $formSlice));

        // Creating a task drives the real lifecycle end to end (sync queue).
        $page->call('createTask');
        $task->refresh();
        $this->assertSame(AgentTask::STATUS_AWAITING_APPROVAL, $task->status);

        $this->cleanup($repoPath);
    }

    // ── G5 — the human status dictionary ────────────────────────────────

    public function test_every_stored_state_maps_to_a_human_status(): void
    {
        $owner = $this->agentOwner();
        [$project, $repoPath] = $this->agentFixtureProject($owner);

        $cases = [
            AgentTask::STATUS_QUEUED => 'Waiting to start',
            AgentTask::STATUS_RUNNING => 'Working',
            AgentTask::STATUS_AWAITING_APPROVAL => 'Ready for review',
            AgentTask::STATUS_APPLYING => 'Applying approved changes',
            AgentTask::STATUS_VERIFYING => 'Verifying',
            AgentTask::STATUS_COMPLETED => 'Completed',
            AgentTask::STATUS_FAILED => 'Needs attention',
            AgentTask::STATUS_CANCELLED => 'Cancelled',
            AgentTask::STATUS_STALE => 'Needs re-review',
        ];

        foreach (array_values($cases) as $i => $expected) {
            $status = array_keys($cases)[$i];
            $task = AgentTask::create([
                'code' => 'AGT-91'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'created_by' => $owner->id,
                'project_id' => $project->id, 'prompt' => 'x', 'title' => 'State probe',
                'status' => $status,
            ]);

            $human = AgentStatusPresenter::status($task);
            $this->assertSame($expected, $human['label'], "Status {$status} humanized wrong.");
            $this->assertNotSame($status, $human['label'], 'A raw enum reached the default face.');
        }

        $this->cleanup($repoPath);
    }

    public function test_a_running_task_refines_its_state_from_the_real_event_stream(): void
    {
        $owner = $this->agentOwner();
        [$project, $repoPath] = $this->agentFixtureProject($owner);

        $task = AgentTask::create([
            'code' => 'AGT-9001', 'created_by' => $owner->id, 'project_id' => $project->id,
            'prompt' => 'x', 'title' => 'Live probe', 'status' => AgentTask::STATUS_RUNNING,
        ]);
        AgentTaskEvent::create(['agent_task_id' => $task->id, 'seq' => 1, 'type' => 'thinking', 'summary' => '…']);
        AgentTaskEvent::create(['agent_task_id' => $task->id, 'seq' => 2, 'type' => 'testing', 'summary' => 'php -l']);

        $this->assertSame('Running tests', AgentStatusPresenter::status($task)['label']);

        $this->cleanup($repoPath);
    }

    // ── G6 — the humanized timeline; raw events stay behind disclosure ──

    public function test_the_timeline_speaks_human_and_hides_raw_events(): void
    {
        [$task, $project, $runtime, $repoPath, $baseUrl] = $this->runHappyTask($this->agentOwner());
        $this->actingAs($task->createdBy);

        // The decisions land in the timeline too (§G6).
        app(AgentTaskService::class)->approve($task, $task->createdBy);

        $page = Livewire::actingAs($task->createdBy)->test(\App\Filament\Pages\DeveloperAgent::class)
            ->set('task', $task->getKey());
        $html = $page->assertSuccessful()->html();

        $this->assertStringContainsString('Changes ready for review', $html);
        $this->assertStringContainsString('Approved by', $html);

        // Raw runtime vocabulary never appears on the default face: check the
        // rendered ACTIVITY section only (the wire snapshot embeds a copy of
        // the data, so raw-string presence alone proves nothing).
        $activityStart = (int) mb_strpos($html, 'data-nx-inspect="agent.activity"');
        $technicalStart = (int) mb_strpos($html, 'data-nx-inspect="agent.technical"');
        $this->assertGreaterThan(0, $activityStart);
        $this->assertGreaterThan($activityStart, $technicalStart);
        $activitySlice = mb_substr($html, $activityStart, $technicalStart - $activityStart);
        foreach (['file_changed', 'SESSION_STARTED', 'COMMAND_EXITED', 'session.started'] as $raw) {
            $this->assertStringNotContainsString($raw, $activitySlice, "Raw event vocabulary ({$raw}) rendered in the activity face.");
        }

        // …and the raw events DO exist behind Technical details (§G6/§G15).
        $technicalSlice = mb_substr($html, $technicalStart);
        $this->assertStringContainsString('file_changed', $technicalSlice);

        $this->cleanup($repoPath);
    }

    // ── G7/G8 — the decision-oriented diff, fingerprint semantics intact ─

    public function test_diff_summary_leads_and_the_fingerprint_stays_behind_disclosure(): void
    {
        [$task, $project, $runtime, $repoPath, $baseUrl] = $this->runHappyTask($this->agentOwner());
        $this->actingAs($task->createdBy);

        $changeset = AgentChangeset::where('agent_task_id', $task->id)->latest('created_at')->first();
        $this->assertNotNull($changeset);

        $html = Livewire::actingAs($task->createdBy)->test(\App\Filament\Pages\DeveloperAgent::class)
            ->set('task', $task->getKey())->assertSuccessful()->html();

        // Summary first: files changed + additions/deletions (§G7).
        $this->assertStringContainsString('files changed', $html);
        $this->assertStringContainsString('+'.$changeset->additions, $html);
        $this->assertStringContainsString('−'.$changeset->deletions, $html);

        // The fingerprint is never on the diff card's default face (§G7) —
        // compare the rendered slices, not the whole document (the wire
        // snapshot embeds a copy of everything).
        $diffStart = (int) mb_strpos($html, 'data-nx-inspect="agent.diff"');
        $technicalStart = (int) mb_strpos($html, 'data-nx-inspect="agent.technical"');
        $defaultFace = mb_substr($html, $diffStart, $technicalStart - $diffStart);
        $this->assertStringNotContainsString($changeset->fingerprint, $defaultFace);

        // The EXACT diff is preserved and rendered (§G8) — no regeneration.
        $this->assertStringContainsString('Update the greeting', $html);

        $this->cleanup($repoPath);
    }

    public function test_a_tampered_changeset_refuses_apply_with_the_re_review_copy(): void
    {
        [$task, $project, $runtime, $repoPath, $baseUrl] = $this->runHappyTask($this->agentOwner());
        $owner = $task->createdBy;
        app(AgentTaskService::class)->approve($task, $owner);

        // Tamper AFTER approval: the fingerprint no longer matches.
        $changeset = AgentChangeset::where('agent_task_id', $task->id)->latest('created_at')->first();
        $changeset->update(['diff' => $changeset->diff."\n+injected line\n"]);

        Livewire::actingAs($owner)->test(\App\Filament\Pages\DeveloperAgent::class)
            ->set('task', $task->getKey())
            ->call('applyTask');

        $task->refresh();
        $this->assertSame(AgentTask::STATUS_AWAITING_APPROVAL, $task->status, 'A tampered changeset was applied.');

        $this->cleanup($repoPath);
    }

    // ── G9/G14 — tests section: pass, fail, applied-but-verification-needs-attention ──

    public function test_verification_pass_renders_a_tests_verdict_with_real_checks(): void
    {
        [$task, $project, $runtime, $repoPath, $baseUrl] = $this->runHappyTask($this->agentOwner());
        $owner = $task->createdBy;
        app(AgentTaskService::class)->approve($task, $owner);
        app(AgentTaskService::class)->apply($task, $owner);
        $task->refresh();

        $this->actingAs($owner);
        $html = Livewire::test(\App\Filament\Pages\DeveloperAgent::class)
            ->set('task', $task->getKey())->assertSuccessful()->html();

        $this->assertSame(AgentTask::STATUS_COMPLETED, $task->status);
        $this->assertStringContainsString('Tests passed', $html);
        // The check name is the REAL command — never an invented label (§G9).
        // The command is built from PHP_BINARY (line ~74), which is php.exe on
        // Windows and php on Linux — assert on the basename, plus the short
        // second check. (Long Windows paths render truncated at 120 chars, so
        // this stays on the command head, never the full path.)
        $this->assertStringContainsString(basename(PHP_BINARY), $html);
        $this->assertStringContainsString('echo ok', $html);
        // The full lifecycle is visible in the timeline (§G13).
        $this->assertStringContainsString('Changes applied', $html);

        $this->cleanup($repoPath);
    }

    public function test_verification_failure_is_not_a_success_state(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('happy');
        $owner = $this->agentOwner();
        [$project, $repoPath] = $this->agentFixtureProject($owner);

        $runtime = $this->mockRuntime($baseUrl);
        $task = app(AgentTaskService::class)->createTask($owner, $project, $runtime, 'mockprovider/mock-small', 'Update the greeting to HELLO TEMM.');
        $task->refresh();
        $this->assertSame(AgentTask::STATUS_AWAITING_APPROVAL, $task->status);

        // Verification fails on purpose — AFTER the apply.
        config(['agent.verification.projects' => [$project->slug => ['"'.PHP_BINARY.'" -r "exit(1);"']]]);
        app(AgentTaskService::class)->approve($task, $owner);
        app(AgentTaskService::class)->apply($task, $owner);
        $task->refresh();

        $this->actingAs($owner);
        $html = Livewire::test(\App\Filament\Pages\DeveloperAgent::class)
            ->set('task', $task->getKey())->assertSuccessful()->html();

        // Applied but verification needs attention — never "Completed" (§G14).
        $this->assertSame(AgentTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('1 check(s) failed', $html);
        $this->assertStringContainsString('The changes were applied, but verification needs attention', $html);
        $this->assertStringNotContainsString('Tests passed', $html);

        $this->cleanup($repoPath);
    }

    // ── G10/G11/G12 — the decision card ─────────────────────────────────

    public function test_ready_for_review_is_the_dominant_decision_state(): void
    {
        [$task, $project, $runtime, $repoPath, $baseUrl] = $this->runHappyTask($this->agentOwner());
        $this->actingAs($task->createdBy);

        $html = Livewire::test(\App\Filament\Pages\DeveloperAgent::class)
            ->set('task', $task->getKey())->assertSuccessful()->html();

        // Exactly the two decisions (§G10) — no bare Apply before approval.
        $this->assertStringContainsString('Ready for review', $html);
        $this->assertStringContainsString('Approve &amp; apply', $html);
        $this->assertStringContainsString('>Reject<', $html);
        $this->assertStringNotContainsString('Apply approved changes', $html);
        // §G11 — the approval means-what copy.
        $this->assertStringContainsString('then runs verification', $html);
        $this->assertStringContainsString('No deployment will happen automatically.', $html);

        $this->cleanup($repoPath);
    }

    public function test_reject_declines_without_applying_and_stays_auditable(): void
    {
        [$task, $project, $runtime, $repoPath, $baseUrl] = $this->runHappyTask($this->agentOwner());
        $owner = $task->createdBy;

        Livewire::actingAs($owner)->test(\App\Filament\Pages\DeveloperAgent::class)
            ->set('task', $task->getKey())
            ->set('rejectNote', 'Wrong approach')
            ->call('rejectTask');

        $task->refresh();
        // §G12 — declining means: nothing applied, everything auditable, the
        // diff and history remain. (No approval row exists — nothing was
        // ever approved; the audit ledger carries the refusal.)
        $this->assertSame(AgentTask::STATUS_COMPLETED, $task->status, 'A rejected task stayed in review.');
        $this->assertNotNull(\DB::table('admin_audit_entries')->where('action', 'AGENT_APPROVAL_REJECTED')->first(), 'The rejection was not audited.');
        $this->assertNotNull(AgentChangeset::where('agent_task_id', $task->id)->first(), 'The diff was erased on rejection.');
        $this->assertTrue($task->events()->where('summary', 'like', '%declined%')->exists(), 'The decision never reached the timeline.');
        $this->assertNull($task->applied_at, 'A rejected task applied something.');

        $this->cleanup($repoPath);
    }

    // ── G10/G22 — apply requires approval; cross-interface state ────────

    public function test_apply_is_refused_before_any_approval_exists(): void
    {
        [$task, $project, $runtime, $repoPath, $baseUrl] = $this->runHappyTask($this->agentOwner());
        $owner = $task->createdBy;

        // §G10 — the UI offers no apply before an approval exists…
        $page = Livewire::actingAs($owner)->test(\App\Filament\Pages\DeveloperAgent::class)
            ->set('task', $task->getKey());
        $this->assertStringNotContainsString('Apply approved changes', $page->html());

        // …and the service refuses regardless (the UI is not the boundary).
        $this->expectException(\App\Services\Agent\Contract\AgentRuntimeException::class);
        app(AgentTaskService::class)->apply($task, $owner);
    }

    public function test_cross_interface_state_cli_create_web_approve_cli_apply(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('happy');
        $owner = $this->agentOwner();
        [$project, $repoPath] = $this->agentFixtureProject($owner);
        config(['agent.verification.projects' => [$project->slug => ['"'.PHP_BINARY.'" -r "exit(0);"']]]);
        $runtime = $this->mockRuntime($baseUrl);

        // CLI creates (§G22: the CLI and the web share AgentTaskService).
        $this->artisan('agent:run', [
            '--project' => $project->slug, '--user' => $owner->email,
            '--model' => 'mockprovider/mock-small',
            'prompt' => 'Update the greeting to HELLO TEMM.',
        ]);
        $task = AgentTask::query()->latest('created_at')->first();
        $this->assertSame(AgentTask::STATUS_AWAITING_APPROVAL, $task->status);

        // Web approves.
        Livewire::actingAs($owner)->test(\App\Filament\Pages\DeveloperAgent::class)
            ->set('task', $task->getKey())
            ->call('approveTaskOnly');
        $task->refresh();
        $this->assertNotNull(AgentApproval::where('agent_task_id', $task->id)->whereNull('consumed_at')->first());

        // CLI applies; the web then reflects the final state.
        $this->artisan('agent:apply', ['code' => $task->code, '--user' => $owner->email]);
        $task->refresh();
        $this->assertSame(AgentTask::STATUS_COMPLETED, $task->status);

        $html = Livewire::actingAs($owner)->test(\App\Filament\Pages\DeveloperAgent::class)
            ->set('task', $task->getKey())->assertSuccessful()->html();
        $this->assertStringContainsString('Completed', $html);
        $this->assertStringContainsString('Tests passed', $html);

        $this->cleanup($repoPath);
    }

    // ── G17/G18/G19 — cancel, failure recovery, explicit retry ──────────

    public function test_cancel_is_honest_and_leaves_other_tasks_unaffected(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('abortable');
        $owner = $this->agentOwner();
        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $runtime = $this->mockRuntime($baseUrl);

        Queue::fake();
        $service = app(AgentTaskService::class);
        $task = $service->createTask($owner, $project, $runtime, 'mockprovider/mock-small', 'Long running task one.');
        $other = $service->createTask($owner, $project, $runtime, 'mockprovider/mock-small', 'Long running task two.');
        Queue::assertPushed(\App\Jobs\Agent\RunAgentTask::class, 2);

        Livewire::actingAs($owner)->test(\App\Filament\Pages\DeveloperAgent::class)
            ->set('task', $task->getKey())
            ->call('cancelTask');

        $task->refresh();
        $other->refresh();
        $this->assertSame(AgentTask::STATUS_CANCELLED, $task->status);
        $this->assertSame(AgentTask::STATUS_QUEUED, $other->status, 'An unrelated task was affected by the cancellation.');

        // §G17 — the cancel UI copy is honest and bounded (rendered only when
        // a cancellable task is selected).
        $html = Livewire::actingAs($owner)->test(\App\Filament\Pages\DeveloperAgent::class)
            ->set('task', $other->getKey())
            ->assertSuccessful()->html();
        $this->assertStringContainsString('Changes already applied are not automatically reverted.', $html);
        $this->assertStringNotContainsString('Abort session', $html);

        $this->cleanup($repoPath);
    }

    public function test_a_failed_task_names_the_problem_and_offers_explicit_retry(): void
    {
        $this->fastAgentConfig();
        // 'silent' never emits events and reports busy — the honest TIMEOUT failure.
        $baseUrl = $this->startMockOpenCode('silent');
        $owner = $this->agentOwner();
        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $runtime = $this->mockRuntime($baseUrl);

        $task = app(AgentTaskService::class)->createTask($owner, $project, $runtime, 'mockprovider/mock-small', 'Update the greeting to HELLO TEMM.', 'Task that will time out.');
        $task->refresh();
        $this->assertSame(AgentTask::STATUS_FAILED, $task->status, 'The silent scenario did not fail the task.');

        $page = Livewire::actingAs($owner)->test(\App\Filament\Pages\DeveloperAgent::class)
            ->set('task', $task->getKey());

        // §G18 — classified problem + stage + recovery; no raw category token.
        $html = $page->assertSuccessful()->html();
        $this->assertStringContainsString('Needs attention', $html);
        $this->assertStringNotContainsString('TIMEOUT', $this->visibleFace($html));
        $this->assertStringContainsString('Start a new task from this prompt', $html);

        // §G19 — explicit re-use: the form is prefilled, nothing is created yet.
        $page->call('newTaskFromPrompt', $task->getKey());
        $this->assertSame('Task that will time out.', $page->get('taskForm.title'));
        $this->assertNull($page->get('task'), 'Retry silently selected a task.');
        $this->assertSame(1, AgentTask::count(), 'Retry created a task silently.');

        $this->cleanup($repoPath);
    }

    // ── G20/G21 — history filters + empty state ─────────────────────────

    public function test_task_history_filters_and_marks_attention(): void
    {
        $owner = $this->agentOwner();
        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $this->actingAs($owner);

        foreach ([AgentTask::STATUS_RUNNING, AgentTask::STATUS_AWAITING_APPROVAL, AgentTask::STATUS_COMPLETED, AgentTask::STATUS_FAILED, AgentTask::STATUS_CANCELLED] as $i => $status) {
            AgentTask::create([
                'code' => 'AGT-7'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'created_by' => $owner->id,
                'project_id' => $project->id, 'prompt' => 'x', 'title' => 'Probe '.$status,
                'status' => $status,
            ]);
        }

        $page = Livewire::test(\App\Filament\Pages\DeveloperAgent::class);
        $this->assertCount(5, $page->instance()->taskList());

        $page->set('historyFilter', 'active');
        $this->assertCount(1, $page->instance()->taskList());
        $page->set('historyFilter', 'ready');
        $this->assertCount(1, $page->instance()->taskList());
        $page->set('historyFilter', 'failed');
        $rows = $page->instance()->taskList();
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['attention'], 'A failed task carried no attention indicator.');
        $page->set('historyFilter', 'cancelled');
        $this->assertCount(1, $page->instance()->taskList());

        $this->cleanup($repoPath);
    }

    public function test_the_empty_state_teaches_when_the_runtime_is_ready(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('happy');
        $owner = $this->agentOwner();
        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $this->actingAs($owner);
        $this->mockRuntime($baseUrl);

        // With a runtime and a project, the empty state teaches the product;
        // without them it switches to the runtime recovery state (§G21),
        // which the recovery tests assert separately.
        $html = Livewire::test(\App\Filament\Pages\DeveloperAgent::class)->assertSuccessful()->html();
        $this->assertStringContainsString('No agent tasks yet.', $html);
        $this->assertStringContainsString('Ask Developer Agent to make a change in an isolated workspace.', $html);

        $this->cleanup($repoPath);
    }

    // ── G23/G24 — model discovery: real names, searchable, no inventions ─

    public function test_model_discovery_uses_a_searchable_picker_with_real_names(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('happy');
        $owner = $this->agentOwner();
        $this->actingAs($owner);
        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $runtime = $this->mockRuntime($baseUrl);

        $page = Livewire::test(\App\Filament\Pages\DeveloperAgent::class);
        $options = $page->instance()->modelOptions[$runtime->id] ?? [];
        $this->assertNotEmpty($options, 'Model discovery returned nothing.');
        foreach ($options as $option) {
            $this->assertStringContainsString('/', $option['value'], 'Model ids must be canonical provider/model ids.');
        }

        $html = $page->assertSuccessful()->html();
        $this->assertStringContainsString('agent-model-options', $html, 'The model picker is not searchable (datalist missing).');
        foreach (['Free', 'Recommended for you', 'Best value'] as $invented) {
            $this->assertStringNotContainsString($invented, $html, 'An invented quality label reached the picker.');
        }

        $this->cleanup($repoPath);
    }

    // ── G25 — permissions ────────────────────────────────────────────────

    public function test_a_project_only_member_cannot_open_the_workbench_and_holds_no_decision_rights(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('happy');
        $owner = $this->agentOwner();
        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $runtime = $this->mockRuntime($baseUrl);

        $viewer = User::factory()->create(['is_admin' => false, 'cp_role' => null]);
        ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $viewer->id,
            'role' => Roles::VIEWER, 'status' => 'active', 'granted_at' => now(),
        ]);

        // The workbench is a platform surface (platform-level agents.view):
        // a project-only viewer is refused at the door — honestly, with 403.
        $this->actingAs($viewer)->get('/admin/developer-agent')->assertForbidden();

        // At the SERVICE layer (the real boundary), the same viewer holds
        // view-only rights on the project: no approve, no apply, no cancel.
        $access = \App\Services\Access\Access::for($viewer);
        $this->assertTrue($access->allows(\App\Services\Access\Capability::AGENTS_VIEW, 'project', $project->workspace_id, $project));
        $this->assertFalse($access->allows(\App\Services\Access\Capability::AGENTS_RUN, 'project', $project->workspace_id, $project));
        $this->assertFalse($access->allows(\App\Services\Access\Capability::AGENTS_APPROVE, 'project', $project->workspace_id, $project));
        $this->assertFalse($access->allows(\App\Services\Access\Capability::AGENTS_APPLY, 'project', $project->workspace_id, $project));

        $this->cleanup($repoPath);
    }

    public function test_the_developer_role_runs_but_cannot_approve(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('happy');
        $owner = $this->agentOwner();
        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $runtime = $this->mockRuntime($baseUrl);

        $dev = User::factory()->create(['is_admin' => false, 'cp_role' => null]);
        ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $dev->id,
            'role' => Roles::DEVELOPER, 'status' => 'active', 'granted_at' => now(),
        ]);

        // The developer CAN create tasks through the service (§G25: runner).
        $task = app(AgentTaskService::class)->createTask($dev, $project, $runtime, 'mockprovider/mock-small', 'Developer boundary probe.');
        $task->refresh();
        $this->assertSame(AgentTask::STATUS_AWAITING_APPROVAL, $task->status);

        // …but holds no decision rights (§G25: cannot approve unless allowed).
        $access = \App\Services\Access\Access::for($dev);
        $this->assertTrue($access->allows(\App\Services\Access\Capability::AGENTS_RUN, 'project', $project->workspace_id, $project));
        $this->assertFalse($access->allows(\App\Services\Access\Capability::AGENTS_APPROVE, 'project', $project->workspace_id, $project), 'A developer role could approve.');
        $this->assertFalse($access->allows(\App\Services\Access\Capability::AGENTS_APPLY, 'project', $project->workspace_id, $project));
        $this->assertFalse($access->allows(\App\Services\Access\Capability::AGENTS_CANCEL, 'project', $project->workspace_id, $project));

        // …and the boundary refuses the approval attempt server-side (403).
        try {
            app(AgentTaskService::class)->approve($task, $dev);
            $this->fail('A developer approved a changeset.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode(), 'The refusal was not a capability abort.');
        }

        $this->cleanup($repoPath);
    }

    // ── G26 — Arabic ─────────────────────────────────────────────────────

    public function test_the_workbench_renders_translated_in_arabic(): void
    {
        $owner = $this->agentOwner();
        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $this->actingAs($owner);
        app()->setLocale('ar');

        $html = Livewire::test(\App\Filament\Pages\DeveloperAgent::class)->assertSuccessful()->html();
        $this->assertStringContainsString('لم يُهيَّأ وكيل المطوّر بعد.', $html);

        app()->setLocale('en');
        $this->cleanup($repoPath);
    }

    public function test_human_statuses_and_timeline_render_in_arabic(): void
    {
        $owner = $this->agentOwner();
        [$project, $repoPath] = $this->agentFixtureProject($owner);

        $task = AgentTask::create([
            'code' => 'AGT-6001', 'created_by' => $owner->id, 'project_id' => $project->id,
            'prompt' => 'x', 'title' => 'AR probe', 'status' => AgentTask::STATUS_AWAITING_APPROVAL,
        ]);
        AgentTaskEvent::create([
            'agent_task_id' => $task->id, 'seq' => 1, 'type' => 'status',
            'summary' => 'Approved by Ahmed', 'payload' => ['kind' => 'approval', 'actor' => 'أحمد'],
        ]);

        app()->setLocale('ar');
        $this->assertSame('جاهز للمراجعة', AgentStatusPresenter::status($task)['label']);
        $this->assertSame('وافق أحمد', AgentStatusPresenter::timelineLabel($task->events()->first()));
        app()->setLocale('en');

        $this->cleanup($repoPath);
    }

    // ── G28 — bounded render with heavy history ─────────────────────────

    public function test_the_detail_render_stays_bounded_with_many_events(): void
    {
        $owner = $this->agentOwner();
        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $this->actingAs($owner);

        $task = AgentTask::create([
            'code' => 'AGT-5001', 'created_by' => $owner->id, 'project_id' => $project->id,
            'prompt' => 'x', 'title' => 'Heavy history', 'status' => AgentTask::STATUS_RUNNING,
        ]);
        $rows = [];
        for ($i = 1; $i <= 500; $i++) {
            $rows[] = ['id' => (string) \Illuminate\Support\Str::uuid(), 'agent_task_id' => $task->id, 'seq' => $i, 'type' => 'thinking', 'summary' => 'Event '.$i, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('agent_task_events')->insert($rows);

        DB::enableQueryLog();
        $page = Livewire::test(\App\Filament\Pages\DeveloperAgent::class)
            ->set('task', $task->getKey())
            ->assertSuccessful();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // §G28: the timeline is bounded (30), the raw event slice is bounded
        // (50), and the render does not scale with the 500-row history.
        $this->assertCount(30, $page->instance()->detailData()['timeline']);
        $this->assertSame(500, $page->instance()->detailData()['event_count']);
        $this->assertLessThan(80, $queries, 'The task detail ran '.$queries.' queries with 500 events.');

        $this->cleanup($repoPath);
    }

    // ── G30 — the Inspect registry is accurate ──────────────────────────

    public function test_workbench_inspect_surfaces_are_registered_and_enforced(): void
    {
        foreach (['agent.new_task', 'agent.tasks', 'agent.detail', 'agent.diff', 'agent.verification', 'agent.decision', 'agent.activity', 'agent.technical'] as $key) {
            $this->assertNotNull(\App\Services\Product\ComponentRegistry::validate($key), "The Inspect surface {$key} is not registered.");
            $this->assertStringContainsString('agents.', (string) \App\Services\Product\ComponentRegistry::definition($key)['capability']);
        }

        // §G30 — no stale pre-Phase-G keys remain.
        foreach (['agent-new-task', 'agent-detail', 'agent-activity'] as $stale) {
            $this->assertNull(\App\Services\Product\ComponentRegistry::validate($stale), "The stale key {$stale} still validates.");
        }

        // The redesigned page carries the registered keys.
        $blade = file_get_contents(__DIR__.'/../../../resources/views/filament/pages/developer-agent.blade.php');
        $this->assertStringContainsString('data-nx-inspect="agent.detail"', (string) $blade);
        $this->assertStringContainsString('data-nx-inspect="agent.decision"', (string) $blade);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** The rendered face without the wire snapshot JSON. */
    protected function visibleFace(string $html): string
    {
        $marker = 'data-nx-inspect="agent.detail"';

        $start = mb_strpos($html, $marker);

        return $start === false ? $html : mb_substr($html, $start);
    }

    /** Remove the leading wire:snapshot attribute blob from a slice. */
    protected function stripSnapshot(string $html): string
    {
        return preg_replace('/wire:snapshot="[^"]*"/', '', $html) ?? $html;
    }
}
