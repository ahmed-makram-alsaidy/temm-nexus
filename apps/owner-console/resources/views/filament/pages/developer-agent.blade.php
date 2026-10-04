{{--
    Phase 43 — Developer Agent workbench.

    Create → watch → diff → approve → apply → verify. All state changes go
    through AgentTaskService (shared with the CLI); this page only renders
    and collects intent. Live updates via wire:poll (the console's polling
    architecture — no websockets involved).
--}}
<x-filament-panels::page>
    @php
        $task = $this->currentTask();
        $access = \App\Services\Access\Access::for(auth()->user());
        $projects = $this->runnableProjects();
        $runtimes = \App\Filament\Pages\DeveloperAgent::runtimes();
        $modelOptions = $this->modelOptions[$this->taskForm['runtime_id']] ?? [];
        $tasks = $this->taskList();
        $events = $task !== null ? $task->events()->latest('seq')->limit(100)->get()->reverse()->values() : collect();
        $commands = $task !== null ? $task->commands()->latest('created_at')->limit(50)->get() : collect();
        $changeset = $task !== null ? \App\Models\AgentChangeset::where('agent_task_id', $task->id)->latest('created_at')->first() : null;
        $changedPaths = $changeset !== null ? \App\Services\Agent\AgentChangesetService::changedPaths((string) $changeset->diff) : [];
        $approvals = $task !== null ? $task->approvals()->latest('created_at')->get() : collect();
        $verification = $task !== null ? $task->verifications()->latest('created_at')->first() : null;
        $canApprove = $task !== null && $access->allows(\App\Services\Access\Capability::AGENTS_APPROVE, 'project', $task->workspace_id, $task->project_id);
        $canApply = $task !== null && $access->allows(\App\Services\Access\Capability::AGENTS_APPLY, 'project', $task->workspace_id, $task->project_id);
        $canCancel = $task !== null && $access->allows(\App\Services\Access\Capability::AGENTS_CANCEL, 'project', $task->workspace_id, $task->project_id);
        $statusClass = $task !== null ? match ($task->status) {
            \App\Models\AgentTask::STATUS_COMPLETED => 'nx-status--success',
            \App\Models\AgentTask::STATUS_FAILED => 'nx-status--danger',
            \App\Models\AgentTask::STATUS_CANCELLED, \App\Models\AgentTask::STATUS_STALE => 'nx-status--warning',
            default => 'nx-status--info',
        } : 'nx-status--neutral';
    @endphp

    <div class="nx-settings-grid" @if ($task !== null && ! $task->isTerminal()) wire:poll.5s @endif>
        {{-- Task creation --}}
        <section class="nx-card" data-nx-inspect="agent-new-task" data-nx-inspect-label="New agent task">
            <h2 class="nx-card__title">{{ __('agents.new_task_title') }}</h2>
            <p class="nx-hint">{{ __('agents.new_task_help') }}</p>

            @if (count($projects) === 0 || count($runtimes) === 0)
                <p class="nx-empty">{{ __('agents.new_task_unavailable') }}</p>
            @else
                <div class="nx-form-grid">
                    <div class="nx-field">
                        <label for="agent-project">{{ __('agents.field_project') }}</label>
                        <select id="agent-project" wire:model.live="taskForm.project_id">
                            @foreach ($projects as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="nx-field">
                        <label for="agent-runtime">{{ __('agents.field_runtime') }}</label>
                        <select id="agent-runtime" wire:model.live="taskForm.runtime_id">
                            @foreach ($runtimes as $runtime)
                                <option value="{{ $runtime->id }}">{{ $runtime->display_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="nx-field">
                        <label for="agent-task-model">{{ __('agents.field_model') }}</label>
                        <select id="agent-task-model" wire:model="taskForm.model">
                            <option value="">{{ __('agents.model_runtime_default') }}</option>
                            @foreach ($modelOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        <p class="nx-hint">{{ __('agents.model_helper') }}</p>
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
                    <button type="button" class="nx-btn nx-btn--small" wire:click="loadModels">{{ __('agents.discover_models') }}</button>
                </div>
            @endif
        </section>

        {{-- Task list --}}
        <section class="nx-card" data-nx-inspect="agent-tasks" data-nx-inspect-label="Agent tasks">
            <h2 class="nx-card__title">{{ __('agents.tasks_title') }}</h2>

            @if (count($tasks) === 0)
                <p class="nx-empty">{{ __('agents.tasks_empty') }}</p>
            @else
                <ul class="nx-list">
                    @foreach ($tasks as $item)
                        <li>
                            <button type="button" class="nx-btn nx-btn--small @if ($item['selected']) nx-btn--primary @endif" wire:click="selectTask('{{ $item['id'] }}')">
                                <span dir="ltr">{{ $item['code'] }}</span>
                                · {{ $item['title'] }}
                                · {{ __('agents.status_'.$item['status']) }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{-- Selected task --}}
    @if ($task !== null)
        <section class="nx-card" data-nx-inspect="agent-detail" data-nx-inspect-label="Agent task detail">
            <h2 class="nx-card__title">
                <span dir="ltr">{{ $task->code }}</span>
                — {{ $task->title }}
                <span class="nx-status {{ $statusClass }}">{{ __('agents.status_'.$task->status) }}</span>
            </h2>

            <dl class="nx-status-list">
                <div class="nx-status-list__row">
                    <dt>{{ __('agents.field_model') }}</dt>
                    <dd><span dir="ltr">{{ $task->model ?? __('agents.model_runtime_default') }}</span></dd>
                </div>
                <div class="nx-status-list__row">
                    <dt>{{ __('agents.field_runtime') }}</dt>
                    <dd>{{ $task->runtime?->display_name ?? __('agents.unknown') }}</dd>
                </div>
                <div class="nx-status-list__row">
                    <dt>{{ __('agents.field_base_revision') }}</dt>
                    <dd><span dir="ltr" class="nx-code">{{ \Illuminate\Support\Str::limit($task->base_revision ?? '', 12, '') }}</span></dd>
                </div>
                <div class="nx-status-list__row">
                    <dt>{{ __('agents.field_prompt') }}</dt>
                    <dd>{{ \Illuminate\Support\Str::limit($task->prompt, 300) }}</dd>
                </div>
                @if ($task->error_category)
                    <div class="nx-status-list__row">
                        <dt>{{ __('agents.field_error') }}</dt>
                        <dd><span class="nx-status nx-status--danger">{{ $task->error_category }}</span> {{ $task->error_message }}</dd>
                    </div>
                @endif
                @if ($task->usage && ($task->usage['tokens'] || $task->usage['cost'] !== null))
                    <div class="nx-status-list__row">
                        <dt>{{ __('agents.field_usage') }}</dt>
                        <dd><span dir="ltr">{{ json_encode($task->usage) }}</span></dd>
                    </div>
                @endif
            </dl>

            {{-- Approval actions --}}
            <div class="nx-grid--actions">
                @if ($task->status === 'awaiting_approval')
                    @if ($canApprove)
                        <button type="button" class="nx-btn nx-btn--primary" wire:click="approveTask">{{ __('agents.approve') }}</button>
                        <button type="button" class="nx-btn" wire:click="rejectTask">{{ __('agents.reject') }}</button>
                    @endif
                    @if ($canApply && $approvals->where('status', 'approved')->whereNull('consumed_at')->isNotEmpty())
                        <button type="button" class="nx-btn nx-btn--primary" wire:click="applyTask">{{ __('agents.apply') }}</button>
                    @endif
                @endif
                @if ($canCancel && ! $task->isTerminal())
                    <button type="button" class="nx-btn" wire:click="cancelTask">{{ __('agents.cancel_task') }}</button>
                @endif
            </div>
        </section>

        {{-- Activity stream --}}
        <section class="nx-card" data-nx-inspect="agent-activity" data-nx-inspect-label="Activity">
            <h2 class="nx-card__title">{{ __('agents.activity_title') }}</h2>
            @if ($events->isEmpty())
                <p class="nx-empty">{{ __('agents.activity_empty') }}</p>
            @else
                <ul class="nx-timeline">
                    @foreach ($events as $event)
                        <li>
                            <span class="nx-tag">{{ __('agents.event_'.$event->type) }}</span>
                            {{ $event->summary ?? '' }}
                            @if ($event->payload && isset($event->payload['path']))
                                · <span dir="ltr" class="nx-code">{{ $event->payload['path'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Commands --}}
        <section class="nx-card" data-nx-inspect="agent-commands" data-nx-inspect-label="Commands">
            <h2 class="nx-card__title">{{ __('agents.commands_title') }}</h2>
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
        </section>

        {{-- Diff / changeset --}}
        @if ($changeset !== null)
            <section class="nx-card" data-nx-inspect="agent-diff" data-nx-inspect-label="Changeset diff">
                <h2 class="nx-card__title">{{ __('agents.diff_title') }}</h2>
                <dl class="nx-status-list">
                    <div class="nx-status-list__row">
                        <dt>{{ __('agents.diff_files') }}</dt>
                        <dd>
                            <span dir="ltr">+{{ $changeset->files_added }} / ~{{ $changeset->files_modified }} / −{{ $changeset->files_deleted }}</span>
                            · <span dir="ltr">+{{ $changeset->additions }} / −{{ $changeset->deletions }}</span>
                        </dd>
                    </div>
                    <div class="nx-status-list__row">
                        <dt>{{ __('agents.diff_fingerprint') }}</dt>
                        <dd><span dir="ltr" class="nx-code">{{ \Illuminate\Support\Str::limit($changeset->fingerprint, 16, '') }}</span></dd>
                    </div>
                </dl>
                @if (count($changedPaths) > 0)
                    <ul class="nx-list nx-list--compact">
                        @foreach ($changedPaths as $path)
                            <li><span dir="ltr" class="nx-code">{{ $path }}</span></li>
                        @endforeach
                    </ul>
                @endif
                @if ($changeset->truncated)
                    <p class="nx-note nx-note--danger">{{ __('agents.diff_truncated') }}</p>
                @endif
                <pre class="nx-code" dir="ltr">{{ $changeset->diff }}</pre>
            </section>
        @endif

        {{-- Verification --}}
        @if ($verification !== null)
            <section class="nx-card" data-nx-inspect="agent-verification" data-nx-inspect-label="Verification">
                <h2 class="nx-card__title">
                    {{ __('agents.verification_title') }}
                    @if ($verification->status === 'passed')
                        <span class="nx-status nx-status--success">{{ __('agents.verification_passed') }}</span>
                    @elseif ($verification->status === 'failed')
                        <span class="nx-status nx-status--danger">{{ __('agents.verification_failed') }}</span>
                    @else
                        <span class="nx-status nx-status--warning">{{ __('agents.verification_error') }}</span>
                    @endif
                </h2>
                <table class="nx-table">
                    <thead>
                        <tr>
                            <th>{{ __('agents.field_command') }}</th>
                            <th>{{ __('agents.field_exit_code') }}</th>
                            <th>{{ __('agents.field_duration') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($verification->commands ?? [] as $command)
                            <tr>
                                <td><span dir="ltr" class="nx-code">{{ $command['command'] }}</span></td>
                                <td><span dir="ltr">{{ $command['exit_code'] ?? '—' }}</span></td>
                                <td><span dir="ltr">{{ $command['duration_ms'] ?? '—' }} ms</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif
    @endif
</x-filament-panels::page>
