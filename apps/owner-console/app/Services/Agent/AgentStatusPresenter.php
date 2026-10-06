<?php

namespace App\Services\Agent;

use App\Models\AgentTask;
use App\Models\AgentTaskEvent;

/**
 * 0.6.0 Phase G — the product-facing status dictionary and activity
 * humanizer for agent tasks (§G5, §G6, §G18).
 *
 * The stored task enums and raw runtime events are the LEDGER; this presenter
 * maps them to human states and sentences for the workbench. It never mutates
 * stored values, never invents states that the ledger does not support, and
 * never exposes session ids, event ids or raw SSE vocabulary on the default
 * face — those live in the task's Technical details.
 */
final class AgentStatusPresenter
{
    /**
     * The human state of a task, with the presentation tone. For a RUNNING
     * task the latest meaningful event refines the state (Planning / Editing
     * files / Running tests), exactly as the audit's status-first model
     * describes — derived from the real event stream, never guessed.
     *
     * @return array{key: string, label: string, tone: string, detail: ?string}
     */
    public static function status(AgentTask $task): array
    {
        $base = match ($task->status) {
            AgentTask::STATUS_QUEUED => ['queued', __('agents.human_status_queued'), 'neutral'],
            AgentTask::STATUS_STARTING => ['starting', __('agents.human_status_starting'), 'info'],
            AgentTask::STATUS_RUNNING => self::runningSubstate($task),
            AgentTask::STATUS_AWAITING_APPROVAL => ['awaiting_approval', __('agents.human_status_awaiting_approval'), 'attention'],
            AgentTask::STATUS_APPLYING => ['applying', __('agents.human_status_applying'), 'info'],
            AgentTask::STATUS_VERIFYING => ['verifying', __('agents.human_status_verifying'), 'info'],
            AgentTask::STATUS_COMPLETED => ['completed', __('agents.human_status_completed'), 'success'],
            AgentTask::STATUS_FAILED => ['failed', __('agents.human_status_failed'), 'danger'],
            AgentTask::STATUS_CANCELLED => ['cancelled', __('agents.human_status_cancelled'), 'warning'],
            AgentTask::STATUS_STALE => ['stale', __('agents.human_status_stale'), 'warning'],
            default => ['unknown', __('agents.unknown'), 'neutral'],
        };

        return [
            'key' => $base[0],
            'label' => $base[1],
            'tone' => $base[2],
            'detail' => self::nextStep($task),
        ];
    }

    /** A running task's sub-state, from its latest meaningful event. */
    protected static function runningSubstate(AgentTask $task): array
    {
        // A direct query (not the ordered relation) so the DESC ordering
        // actually controls the result.
        $latestType = AgentTaskEvent::query()
            ->where('agent_task_id', $task->getKey())
            ->whereIn('type', [
                AgentTaskEvent::TYPE_THINKING, AgentTaskEvent::TYPE_EDITING,
                AgentTaskEvent::TYPE_FILE_CHANGED, AgentTaskEvent::TYPE_TESTING,
                AgentTaskEvent::TYPE_COMMAND,
            ])
            ->orderByDesc('seq')
            ->value('type');

        return match ($latestType) {
            AgentTaskEvent::TYPE_THINKING => ['running', __('agents.human_status_planning'), 'info'],
            AgentTaskEvent::TYPE_EDITING, AgentTaskEvent::TYPE_FILE_CHANGED => ['running', __('agents.human_status_editing'), 'info'],
            AgentTaskEvent::TYPE_TESTING => ['running', __('agents.human_status_testing'), 'info'],
            default => ['running', __('agents.human_status_working'), 'info'],
        };
    }

    /** The one "what happens next" sentence per state (§G1). */
    protected static function nextStep(AgentTask $task): ?string
    {
        return match ($task->status) {
            AgentTask::STATUS_QUEUED => __('agents.next_queued'),
            AgentTask::STATUS_STARTING => __('agents.next_starting'),
            AgentTask::STATUS_RUNNING => __('agents.next_running'),
            AgentTask::STATUS_AWAITING_APPROVAL => __('agents.next_awaiting_approval'),
            AgentTask::STATUS_APPLYING => __('agents.next_applying'),
            AgentTask::STATUS_VERIFYING => __('agents.next_verifying'),
            AgentTask::STATUS_COMPLETED => __('agents.next_completed'),
            AgentTask::STATUS_FAILED => __('agents.next_failed'),
            AgentTask::STATUS_CANCELLED => __('agents.next_cancelled'),
            AgentTask::STATUS_STALE => __('agents.next_stale'),
            default => null,
        };
    }

    /**
     * One human sentence for a timeline event (§G6). Returns null for events
     * that carry nothing a user can act on — they still exist for the raw
     * Technical details view and the audit.
     */
    public static function timelineLabel(AgentTaskEvent $event): ?string
    {
        $summary = trim((string) ($event->summary ?? ''));
        $path = is_array($event->payload) ? ($event->payload['path'] ?? null) : null;
        // §G16 — absolute workspace paths never render on the default face:
        // only the file name is human-relevant here.
        $file = $path !== null ? basename((string) $path) : null;

        // Structured decision events (§G6): approve / reject / applied.
        $kind = is_array($event->payload) ? ($event->payload['kind'] ?? null) : null;
        if ($kind === 'approval') {
            return __('agents.timeline_approved', ['name' => (string) ($event->payload['actor'] ?? '')]);
        }
        if ($kind === 'rejection') {
            return __('agents.timeline_declined');
        }
        if ($kind === 'applied') {
            return __('agents.timeline_applied');
        }

        return match ($event->type) {
            AgentTaskEvent::TYPE_STATUS => match (true) {
                $summary === '' => __('agents.timeline_status'),
                str_contains(mb_strtolower($summary), 'awaiting approval') => __('agents.timeline_changeset_ready'),
                str_contains(mb_strtolower($summary), 'starting') => __('agents.timeline_task_started'),
                str_contains(mb_strtolower($summary), 'started') => __('agents.timeline_session_started'),
                default => $summary !== '' ? $summary : __('agents.timeline_status'),
            },
            AgentTaskEvent::TYPE_THINKING => __('agents.timeline_planning'),
            AgentTaskEvent::TYPE_READING => $file !== null
                ? __('agents.timeline_reading_path', ['path' => $file])
                : __('agents.timeline_reading'),
            AgentTaskEvent::TYPE_EDITING => __('agents.timeline_editing'),
            AgentTaskEvent::TYPE_FILE_CHANGED => $file !== null
                ? __('agents.timeline_updated_path', ['path' => $file])
                : __('agents.timeline_updated'),
            AgentTaskEvent::TYPE_COMMAND => $summary !== ''
                ? __('agents.timeline_ran_command', ['command' => mb_substr($summary, 0, 80)])
                : __('agents.timeline_ran_command_short'),
            AgentTaskEvent::TYPE_TESTING => __('agents.timeline_testing'),
            AgentTaskEvent::TYPE_PLAN => __('agents.timeline_plan'),
            AgentTaskEvent::TYPE_PERMISSION => self::permissionLabel($event),
            AgentTaskEvent::TYPE_MESSAGE => $summary !== '' ? $summary : __('agents.timeline_message'),
            AgentTaskEvent::TYPE_ERROR => __('agents.timeline_error', ['reason' => self::errorTitle(
                is_array($event->payload) ? ($event->payload['category'] ?? '') : '',
            )]),
            AgentTaskEvent::TYPE_COMPLETED => __('agents.timeline_completed'),
            default => $summary !== '' ? $summary : null,
        };
    }

    protected static function permissionLabel(AgentTaskEvent $event): string
    {
        $decision = is_array($event->payload) ? ($event->payload['decision'] ?? null) : null;

        return $decision === 'reject'
            ? __('agents.timeline_permission_refused')
            : __('agents.timeline_permission_allowed');
    }

    /** Translated, plain-language title for a stored error category (§G18). */
    public static function errorTitle(?string $category): string
    {
        return match ($category) {
            AgentTask::ERROR_RUNTIME_UNAVAILABLE => __('agents.error_runtime_unavailable'),
            AgentTask::ERROR_RUNTIME_AUTH_FAILED => __('agents.error_runtime_auth_failed'),
            AgentTask::ERROR_MODEL_UNAVAILABLE => __('agents.error_model_unavailable'),
            AgentTask::ERROR_SESSION_FAILED => __('agents.error_session_failed'),
            AgentTask::ERROR_WORKSPACE_FAILED => __('agents.error_workspace_failed'),
            AgentTask::ERROR_COMMAND_FAILED => __('agents.error_command_failed'),
            AgentTask::ERROR_TASK_CANCELLED => __('agents.error_task_cancelled'),
            AgentTask::ERROR_INVALID_RUNTIME_RESPONSE => __('agents.error_invalid_runtime_response'),
            AgentTask::ERROR_TIMEOUT => __('agents.error_timeout'),
            default => __('agents.error_generic'),
        };
    }

    /**
     * The product stage where the failure happened (§G18) — derived from the
     * error category and the task's own record, never from raw internals.
     */
    public static function errorStage(AgentTask $task): ?string
    {
        return match ($task->error_category) {
            AgentTask::ERROR_RUNTIME_UNAVAILABLE, AgentTask::ERROR_RUNTIME_AUTH_FAILED => __('agents.stage_runtime_connection'),
            AgentTask::ERROR_WORKSPACE_FAILED => __('agents.stage_workspace'),
            AgentTask::ERROR_MODEL_UNAVAILABLE => __('agents.stage_model'),
            AgentTask::ERROR_COMMAND_FAILED => __('agents.stage_verification'),
            AgentTask::ERROR_TIMEOUT => __('agents.stage_session'),
            AgentTask::ERROR_SESSION_FAILED, AgentTask::ERROR_INVALID_RUNTIME_RESPONSE => __('agents.stage_session'),
            AgentTask::ERROR_TASK_CANCELLED => __('agents.stage_cancelled'),
            default => null,
        };
    }

    /** Filters for the task history (§G20), as status groups. */
    public const FILTERS = [
        'all' => null,
        'active' => [AgentTask::STATUS_QUEUED, AgentTask::STATUS_STARTING, AgentTask::STATUS_RUNNING, AgentTask::STATUS_APPLYING, AgentTask::STATUS_VERIFYING],
        'ready' => [AgentTask::STATUS_AWAITING_APPROVAL],
        'completed' => [AgentTask::STATUS_COMPLETED],
        'failed' => [AgentTask::STATUS_FAILED, AgentTask::STATUS_STALE],
        'cancelled' => [AgentTask::STATUS_CANCELLED],
    ];
}
