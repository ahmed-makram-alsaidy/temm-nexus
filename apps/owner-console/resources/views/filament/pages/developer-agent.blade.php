{{--
    0.6.0 Phase G — the Developer Agent workbench, STATUS-FIRST (audit §10).

    The default face answers, in order: what is the task, what is the agent
    doing now, what changed, did tests pass, does it need my decision, what
    happens next. Session ids, raw events, command tables, fingerprints and
    runtime internals live ONLY inside Technical details (§G15, §G16).

    Everything renders through AgentStatusPresenter — the stored enums and
    raw events are the ledger; the human words come from lang/. All state
    changes go through AgentTaskService (shared with the CLI); this page only
    renders and collects intent. Live updates via wire:poll while a task is
    active; the runtime probe is re-checked at most once a minute (§G28).
--}}
<x-filament-panels::page>
    @php
        $detail = $this->detailData();
        $task = $detail['task'] ?? null;
        $runtime = $this->runtimeState();
        $canStart = $this->canStartTasks();
        $projects = $this->runnableProjects();
        $runtimes = \App\Filament\Pages\DeveloperAgent::runtimes();
        $modelOptions = $this->modelOptions[$this->taskForm['runtime_id']] ?? [];
        $tasks = $this->taskList();
        $changeset = $detail['changeset'] ?? null;
        $verification = $detail['verification'] ?? null;
    @endphp

    {{-- Quiet runtime state (§G4) — never an internal endpoint or id. --}}
    <div class="nx-agent-head" @if ($task !== null && ! $task->isTerminal()) wire:poll.5s @endif>
        <div class="nx-agent-runtime @if ($runtime['state'] === 'connected') nx-agent-runtime--ok @elseif ($runtime['state'] === 'unavailable') nx-agent-runtime--down @endif">
            <span class="nx-agent-runtime__dot" aria-hidden="true"></span>
            <span class="nx-agent-runtime__label">{{ $runtime['label'] }}</span>
            @if ($runtime['state'] === 'connected' && filled($runtime['version']))
                <span class="nx-agent-runtime__version" dir="ltr">OpenCode {{ $runtime['version'] }}</span>
            @endif
        </div>
    </div>

    {{-- ── Runtime not configured / no runnable project — RECOVERY, not a dead end (§G3) ── --}}
    @unless ($canStart)
        <div class="nx-notice nx-notice--warning">
            <x-filament::icon icon="heroicon-o-command-line" class="h-5 w-5" />
            <div>
                <strong>{{ __('agents.recovery_title') }}</strong>
                <p>
                    @if (count($projects) === 0)
                        {{ __('agents.recovery_no_project') }}
                    @elseif ($this->canConfigureAgents())
                        {{ __('agents.recovery_body_admin') }}
                    @else
                        {{ __('agents.recovery_body_user') }}
                    @endif
                </p>
                @if ($this->canConfigureAgents())
                    <p class="nx-notice__action">
                        <a class="nx-btn" href="{{ \App\Filament\Pages\DeveloperAgentSettings::getUrl() }}">{{ __('agents.recovery_cta') }}</a>
                    </p>
                @endif
            </div>
        </div>
    @endunless

    <div class="nx-settings-grid">
        {{-- ── Start a task (§G2): project, task, model. Nothing internal. ── --}}
        @if ($canStart)
            <section class="nx-card" data-nx-inspect="agent.new_task" data-nx-inspect-label="Start a task">
                <h2 class="nx-card__title">{{ __('agents.new_task_title') }}</h2>
                <p class="nx-hint">{{ __('agents.new_task_help') }}</p>

                <div class="nx-form-grid">
                    <div class="nx-field">
                        <label for="agent-project">{{ __('agents.field_project') }}</label>
                        <select id="agent-project" wire:model.live="taskForm.project_id">
                            @foreach ($projects as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if (count($runtimes) > 1)
                        <div class="nx-field">
                            <label for="agent-runtime">{{ __('agents.field_runtime') }}</label>
                            <select id="agent-runtime" wire:model.live="taskForm.runtime_id">
                                @foreach ($runtimes as $one)
                                    <option value="{{ $one->id }}">{{ $one->display_name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="nx-field">
                        <label for="agent-task-model">{{ __('agents.field_model') }}</label>
                        <input id="agent-task-model" list="agent-model-options" wire:model="taskForm.model"
                               placeholder="{{ __('agents.model_runtime_default') }}" autocomplete="off" />
                        <datalist id="agent-model-options">
                            @foreach ($modelOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </datalist>
                        <p class="nx-hint">
                            {{ __('agents.model_helper') }}
                            @if (count($modelOptions) > 0)
                                <span dir="ltr">· {{ count($modelOptions) }}+</span>
                            @endif
                        </p>
                    </div>

                    <div class="nx-field">
                        <label for="agent-task-title">{{ __('agents.field_title') }}</label>
                        <input id="agent-task-title" type="text" wire:model="taskForm.title" />
                    </div>

                    <div class="nx-field">
                        <label for="agent-task-prompt">{{ __('agents.field_prompt') }}</label>
                        <textarea id="agent-task-prompt" rows="5" wire:model="taskForm.prompt"></textarea>
                    </div>
                </div>

                <div class="nx-grid--actions">
                    <button type="button" class="nx-btn nx-btn--primary" wire:click="createTask">{{ __('agents.start_task') }}</button>
                </div>
            </section>
        @endif

        {{-- ── Task history (§G20): title, project, human status, last active, attention. ── --}}
        <section class="nx-card" data-nx-inspect="agent.tasks" data-nx-inspect-label="Task history">
            <h2 class="nx-card__title">{{ __('agents.tasks_title') }}</h2>

            <div class="nx-agent-filters" role="group" aria-label="{{ __('agents.filter_label') }}">
                @foreach (['all', 'active', 'ready', 'completed', 'failed', 'cancelled'] as $filter)
                    <button type="button"
                            class="nx-agent-filter @if ($this->historyFilter === $filter) nx-agent-filter--on @endif"
                            wire:click="$set('historyFilter', '{{ $filter }}')">
                        {{ __("agents.filter_{$filter}") }}
                    </button>
                @endforeach
            </div>

            @if (count($tasks) === 0)
                @if ($canStart)
                    <x-nx.empty-state
                        icon="heroicon-o-square-3-stack-3d"
                        :title="__('agents.tasks_empty')"
                        :body="__('agents.tasks_empty_body')"
                    />
                @else
                    <x-nx.empty-state
                        icon="heroicon-o-command-line"
                        :title="__('agents.recovery_title')"
                        :body="count($projects) === 0 ? __('agents.recovery_no_project') : __('agents.recovery_body_user')"
                    />
                @endif
            @else
                <ul class="nx-agent-tasklist">
                    @foreach ($tasks as $item)
                        <li>
                            <button type="button"
                                    class="nx-agent-task @if ($item['selected']) nx-agent-task--on @endif"
                                    wire:click="selectTask('{{ $item['id'] }}')">
                                <span class="nx-agent-task__title">
                                    @if ($item['attention'])<span class="nx-agent-task__dot" aria-hidden="true"></span>@endif
                                    {{ \Illuminate\Support\Str::limit($item['title'] ?? '', 64) }}
                                </span>
                                <span class="nx-agent-task__meta">
                                    @if ($item['project']){{ $item['project'] }} · @endif
                                    <span dir="ltr">{{ $item['code'] }}</span>
                                    · <span @class(['nx-agent-state', 'nx-agent-state--'.$item['status_tone']])>{{ $item['status_label'] }}</span>
                                    @if (filled($item['model'])) · <span dir="ltr">{{ $item['model'] }}</span> @endif
                                    · {{ $item['last_active'] }}
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{-- ────────────────────────────────────────────────────────────────────
         THE SELECTED TASK — status-first (§G1)
    ───────────────────────────────────────────────────────────────────── --}}
    @if ($detail !== null)
        @php $d = $detail; $t = $d['task']; $decision = $d['decision']; @endphp

        <section class="nx-card nx-agent-detail" data-nx-inspect="agent.detail" data-nx-inspect-label="Task detail">
            <header class="nx-agent-detail__head">
                <div>
                    <h2 class="nx-card__title">{{ \Illuminate\Support\Str::limit($t->title ?? '', 90) }}</h2>
                    <p class="nx-hint">
                        @if ($d['project_name']){{ $d['project_name'] }} · @endif
                        <span dir="ltr">{{ $t->code }}</span>
                        @if ($d['model']) · <span dir="ltr">{{ $d['model'] }}</span> @endif
                        @if ($d['runtime_name']) · {{ $d['runtime_name'] }} @endif
                    </p>
                </div>
                <span @class(['nx-agent-state', 'nx-agent-state--lg', 'nx-agent-state--'.$d['status']['tone']])>
                    {{ $d['status']['label'] }}
                </span>
            </header>

            <p class="nx-agent-nextstep">{{ $d['status']['detail'] }}</p>

            {{-- §G18 — failed task: what failed, at which stage, what you can do. --}}
            @if ($t->status === \App\Models\AgentTask::STATUS_FAILED)
                <div class="nx-error nx-agent-error" role="alert">
                    <span class="nx-error__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                    </span>
                    <div class="nx-error__content">
                        <p class="nx-error__title">{{ \App\Services\Agent\AgentStatusPresenter::errorTitle($t->error_category) }}</p>
                        @if (\App\Services\Agent\AgentStatusPresenter::errorStage($t) !== null)
                            <p class="nx-error__body">{{ __('agents.error_stage_line', ['stage' => \App\Services\Agent\AgentStatusPresenter::errorStage($t)]) }}</p>
                        @endif
                        <div class="nx-error__actions">
                            @if ($canStart)
                                <button type="button" class="nx-btn" wire:click="newTaskFromPrompt('{{ $t->getKey() }}')">
                                    {{ __('agents.retry_new_task') }}
                                </button>
                            @endif
                            <details class="nx-details nx-details--inline">
                                <summary class="nx-details__summary">{{ __('foundation.error_technical_details') }}</summary>
                                <div class="nx-details__body" dir="ltr">
                                    <code class="nx-tech">{{ \Illuminate\Support\Str::limit((string) $t->error_message, 300) }}</code>
                                </div>
                            </details>
                        </div>
                    </div>
                </div>
            @endif

            {{-- §G8 — stale changeset: the exact re-review copy, no auto-approval. --}}
            @if ($t->status === \App\Models\AgentTask::STATUS_STALE)
                <div class="nx-notice nx-notice--warning" role="alert">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-5 w-5" />
                    <div><strong>{{ __('agents.stale_title') }}</strong><p>{{ __('agents.stale_body') }}</p></div>
                </div>
            @endif

            {{-- §G1 — what happened next, one sentence per state, is above. --}}
            @if (filled($t->prompt))
                <details class="nx-details">
                    <summary class="nx-details__summary">{{ __('agents.prompt_disclosure') }}</summary>
                    <div class="nx-details__body"><p class="nx-agent-prompt">{{ $t->prompt }}</p></div>
                </details>
            @endif
        </section>

        {{-- ── WHAT CHANGED (§G7): summary → file list → the real diff. ── --}}
        @if ($changeset !== null)
            <section class="nx-card" data-nx-inspect="agent.diff" data-nx-inspect-label="What changed">
                <h2 class="nx-card__title">{{ __('agents.diff_title') }}</h2>

                <p class="nx-agent-diff-summary">
                    <strong dir="ltr">{{ $changeset->files_added + $changeset->files_modified + $changeset->files_deleted }}</strong>
                    {{ __('agents.diff_files_changed') }}
                    <span class="nx-agent-diffstat nx-agent-diffstat--add" dir="ltr">+{{ $changeset->additions }}</span>
                    <span class="nx-agent-diffstat nx-agent-diffstat--del" dir="ltr">−{{ $changeset->deletions }}</span>
                </p>

                @if (count($d['changed_paths']) > 0)
                    <ul class="nx-agent-files">
                        @foreach ($d['changed_paths'] as $path)
                            <li><span dir="ltr" class="nx-code">{{ $path }}</span></li>
                        @endforeach
                    </ul>
                @endif

                @if ($changeset->truncated)
                    <p class="nx-note nx-note--danger">{{ __('agents.diff_truncated') }}</p>
                @endif

                <details class="nx-details nx-agent-diff" @if ($t->status === \App\Models\AgentTask::STATUS_AWAITING_APPROVAL || $t->status === \App\Models\AgentTask::STATUS_STALE) open @endif>
                    <summary class="nx-details__summary">{{ __('agents.diff_view') }}</summary>
                    <div class="nx-details__body">
                        {{-- The EXACT changeset bound to approval — never regenerated (§G8). --}}
                        <pre class="nx-code nx-agent-diffcode" dir="ltr">{{ $changeset->diff }}</pre>
                    </div>
                </details>
            </section>
        @endif

        {{-- ── TESTS (§G9): verdict first, then the real checks. ── --}}
        @if ($verification !== null)
            <section class="nx-card" data-nx-inspect="agent.verification" data-nx-inspect-label="Tests">
                <h2 class="nx-card__title">
                    {{ __('agents.verification_title') }}
                    @if ($verification->status === 'passed')
                        <span class="nx-agent-state nx-agent-state--success">{{ __('agents.verification_passed') }}</span>
                    @elseif ($verification->status === 'failed')
                        <span class="nx-agent-state nx-agent-state--danger">{{ __('agents.verification_failed_title', ['count' => count(array_filter($verification->commands ?? [], fn ($c) => ($c['exit_code'] ?? 1) !== 0))]) }}</span>
                    @elseif ($verification->status === 'skipped')
                        <span class="nx-agent-state nx-agent-state--neutral">{{ __('agents.verification_skipped') }}</span>
                    @else
                        <span class="nx-agent-state nx-agent-state--warning">{{ __('agents.verification_error') }}</span>
                    @endif
                </h2>

                @if ($verification->status === 'passed')
                    <p class="nx-hint">{{ __('agents.verification_passed_body') }}</p>
                @elseif ($verification->status === 'failed')
                    <p class="nx-hint">{{ __('agents.verification_failed_body') }}</p>
                @endif

                @if (count($verification->commands ?? []) > 0)
                    <ul class="nx-agent-checks">
                        @foreach ($verification->commands as $command)
                            <li @class(['nx-agent-check', 'nx-agent-check--fail' => ($command['exit_code'] ?? 1) !== 0])>
                                <span class="nx-agent-check__verdict" dir="ltr">{{ ($command['exit_code'] ?? 1) === 0 ? '✓' : '✗' }}</span>
                                <span class="nx-agent-check__name" dir="ltr">{{ \Illuminate\Support\Str::limit((string) $command['command'], 120) }}</span>
                                <span class="nx-agent-check__meta" dir="ltr">{{ $command['duration_ms'] ?? '—' }} ms</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <p class="nx-hint">{{ __('agents.verification_output_note') }}</p>
            </section>
        @endif

        {{-- ── YOUR DECISION (§G10–§G12): the dominant state when review is needed.
             Rendered only for someone who can actually act on it. ── --}}
        @if ($t->status === \App\Models\AgentTask::STATUS_AWAITING_APPROVAL && ($decision['canApprove'] || ($decision['canApply'] && $decision['hasLiveApproval'])))
            <section class="nx-card nx-agent-decision" data-nx-inspect="agent.decision" data-nx-inspect-label="Your decision">
                <h2 class="nx-card__title">{{ __('agents.decision_title') }}</h2>
                <p class="nx-agent-decision__impact">
                    {{ __('agents.decision_impact') }}
                    @if ($changeset !== null)
                        <strong dir="ltr">
                            {{ $changeset->files_added + $changeset->files_modified + $changeset->files_deleted }} {{ __('agents.diff_files_changed') }}
                            · +{{ $changeset->additions }} −{{ $changeset->deletions }}
                        </strong>
                    @endif
                </p>
                <p class="nx-hint">{{ __('agents.decision_explainer') }} {{ __('agents.decision_no_deploy') }}</p>

                <div class="nx-grid--actions">
                    @if ($decision['canApprove'] && $decision['canApply'])
                        <button type="button" class="nx-btn nx-btn--primary" wire:click="approveAndApplyTask">{{ __('agents.approve_and_apply') }}</button>
                    @elseif ($decision['canApprove'])
                        <button type="button" class="nx-btn nx-btn--primary" wire:click="approveTaskOnly">{{ __('agents.approve') }}</button>
                    @endif
                    @if ($decision['canApply'] && $decision['hasLiveApproval'] && ! $decision['canApprove'])
                        <button type="button" class="nx-btn nx-btn--primary" wire:click="applyTask">{{ __('agents.apply_approved') }}</button>
                    @endif
                    @if ($decision['canApprove'])
                        <button type="button" class="nx-btn" wire:click="rejectTask">{{ __('agents.reject') }}</button>
                    @endif
                </div>

                @if ($decision['canApprove'])
                    <div class="nx-field nx-agent-reject-note">
                        <label for="agent-reject-note">{{ __('agents.reject_note_label') }}</label>
                        <input id="agent-reject-note" type="text" wire:model="rejectNote" maxlength="500" />
                    </div>
                @endif

                @if ($decision['canApprove'] && ! $decision['canApply'])
                    <p class="nx-hint">{{ __('agents.approve_without_apply_note') }}</p>
                @endif
            </section>
        @elseif ($t->status === \App\Models\AgentTask::STATUS_RUNNING || $t->status === \App\Models\AgentTask::STATUS_STARTING || $t->status === \App\Models\AgentTask::STATUS_QUEUED || $t->status === \App\Models\AgentTask::STATUS_APPLYING || $t->status === \App\Models\AgentTask::STATUS_VERIFYING)
            @if ($decision['canCancel'])
                <section class="nx-card" data-nx-inspect="agent.cancel" data-nx-inspect-label="Cancel task">
                    <div class="nx-grid--actions nx-agent-cancel">
                        <div>
                            <strong>{{ __('agents.cancel_title') }}</strong>
                            <p class="nx-hint">{{ __('agents.cancel_copy') }}</p>
                        </div>
                        <button type="button" class="nx-btn" wire:click="cancelTask">{{ __('agents.cancel_task') }}</button>
                    </div>
                </section>
            @endif
        @endif

        {{-- ── ACTIVITY (§G6): human sentences, bounded. Raw events stay in Technical details. ── --}}
        <section class="nx-card" data-nx-inspect="agent.activity" data-nx-inspect-label="Activity">
            <h2 class="nx-card__title">{{ __('agents.activity_title') }}</h2>
            @if (count($d['timeline']) === 0)
                <x-nx.empty-state
                    icon="heroicon-o-inbox"
                    compact
                    :title="__('agents.activity_empty_title')"
                    :body="__('agents.activity_empty_body')"
                />
            @else
                <ul class="nx-timeline">
                    @foreach ($d['timeline'] as $row)
                        <li>
                            <span class="nx-agent-timeline__time" dir="ltr">{{ $row['at'] }}</span>
                            {{ $row['label'] }}
                        </li>
                    @endforeach
                </ul>
                @if ($d['event_count'] > count($d['timeline']))
                    <p class="nx-hint">{{ __('agents.timeline_bounded', ['shown' => count($d['timeline']), 'total' => $d['event_count']]) }}</p>
                @endif
            @endif
        </section>

        {{-- ── TECHNICAL DETAILS (§G15/§G16): session, internals, commands, raw events. ── --}}
        <details class="nx-advanced" data-nx-inspect="agent.technical" data-nx-inspect-label="Technical details">
            <summary>{{ __('foundation.technical_details') }}</summary>
            <div class="nx-advanced__body" @if ($task !== null && ! $task->isTerminal()) wire:poll.5s @endif>
                @php
                    $techTask = $this->currentTask();
                    $commands = $techTask !== null ? $techTask->commands()->orderByDesc('created_at')->limit(50)->get() : collect();
                    $rawEvents = $techTask !== null ? $techTask->events()->orderByDesc('seq')->limit(50)->get()->reverse()->values() : collect();
                    $lastApproval = $techTask !== null ? $techTask->approvals()->latest('created_at')->first() : null;
                @endphp

                <div class="nx-fact"><span class="nx-fact__label">{{ __('agents.tech_task_id') }}</span><span class="nx-fact__detail" dir="ltr"><code class="nx-code">{{ $t->getKey() }}</code></span></div>
                @if ($t->runtime_session_id)
                    <div class="nx-fact"><span class="nx-fact__label">{{ __('agents.tech_session_id') }}</span><span class="nx-fact__detail" dir="ltr"><code class="nx-code">{{ $t->runtime_session_id }}</code></span></div>
                @endif
                @if ($t->runtime)
                    <div class="nx-fact"><span class="nx-fact__label">{{ __('agents.tech_runtime') }}</span><span class="nx-fact__detail">{{ $t->runtime->display_name }} <span dir="ltr">({{ $t->runtime->driver }})</span></span></div>
                @endif
                @if ($t->workspaceRecord?->path)
                    <div class="nx-fact"><span class="nx-fact__label">{{ __('agents.tech_workspace_path') }}</span><span class="nx-fact__detail" dir="ltr"><code class="nx-code">{{ $t->workspaceRecord->path }}</code></span></div>
                @endif
                @if (filled($t->base_revision))
                    <div class="nx-fact"><span class="nx-fact__label">{{ __('agents.field_base_revision') }}</span><span class="nx-fact__detail" dir="ltr"><code class="nx-code">{{ \Illuminate\Support\Str::limit($t->base_revision ?? '', 12, '') }}</code></span></div>
                @endif
                @if ($changeset?->fingerprint)
                    <div class="nx-fact"><span class="nx-fact__label">{{ __('agents.diff_fingerprint') }}</span><span class="nx-fact__detail" dir="ltr"><code class="nx-code">{{ $changeset->fingerprint }}</code></span></div>
                @endif
                @if ($lastApproval)
                    <div class="nx-fact">
                        <span class="nx-fact__label">{{ __('agents.tech_approval') }}</span>
                        <span class="nx-fact__detail" dir="ltr">
                            <code class="nx-code">{{ \Illuminate\Support\Str::limit($lastApproval->getKey(), 8, '') }}</code>
                            · {{ $lastApproval->status }}
                            @if ($lastApproval->consumed_at) · {{ __('agents.tech_approval_consumed') }} @endif
                        </span>
                    </div>
                @endif
                @if ($t->usage && ($t->usage['tokens'] || $t->usage['cost'] !== null))
                    <div class="nx-fact"><span class="nx-fact__label">{{ __('agents.field_usage') }}</span><span class="nx-fact__detail" dir="ltr">{{ json_encode($t->usage) }}</span></div>
                @endif

                <h3 class="nx-advanced__heading">{{ __('agents.commands_title') }}</h3>
                @if ($commands->isEmpty())
                    <p class="nx-empty nx-empty--inline">{{ __('agents.commands_empty') }}</p>
                @else
                    <table class="nx-table">
                        <thead>
                            <tr>
                                <th>{{ __('agents.field_command') }}</th>
                                <th>{{ __('agents.field_exit_code') }}</th>
                                <th>{{ __('agents.field_duration') }}</th>
                                <th>{{ __('agents.field_output') }}</th>
                                <th>{{ __('agents.field_status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($commands as $command)
                                <tr>
                                    <td><span dir="ltr" class="nx-code">{{ \Illuminate\Support\Str::limit($command->command, 120) }}</span></td>
                                    <td><span dir="ltr">{{ $command->exit_code ?? '—' }}</span></td>
                                    <td>@if ($command->duration_ms !== null)<span dir="ltr">{{ $command->duration_ms }} ms</span>@else — @endif</td>
                                    <td>@if ($command->output_bytes > 0)<span dir="ltr">{{ number_format($command->output_bytes) }} B</span>@if ($command->truncated) ({{ __('agents.truncated') }})@endif@else — @endif</td>
                                    <td>{{ __('agents.command_status_'.$command->status) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="nx-hint">{{ __('agents.commands_privacy_note') }}</p>
                @endif

                <h3 class="nx-advanced__heading">{{ __('agents.tech_raw_events') }}</h3>
                @if ($rawEvents->isEmpty())
                    <p class="nx-empty nx-empty--inline">{{ __('agents.activity_empty') }}</p>
                @else
                    <ul class="nx-list nx-list--compact">
                        @foreach ($rawEvents as $event)
                            <li><span dir="ltr" class="nx-code">{{ $event->type }}</span> — {{ \Illuminate\Support\Str::limit((string) ($event->summary ?? ''), 140) }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </details>
    @endif
</x-filament-panels::page>
