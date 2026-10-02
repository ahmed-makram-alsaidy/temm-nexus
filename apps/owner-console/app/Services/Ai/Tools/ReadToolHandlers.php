<?php

namespace App\Services\Ai\Tools;

use App\Models\BackupRecord;
use App\Models\Project;
use App\Services\Access\Capability;
use App\Services\Ai\AiContext;
use App\Services\Ai\Scope;
use App\Services\Product\ConnectorCatalogView;
use App\Services\Product\CutoverReadiness;
use App\Services\Product\PlatformPulse;
use App\Services\Product\ProjectPulse;
use Illuminate\Support\Facades\DB;

/**
 * 0.4.0 §18/§19 — the handlers behind the typed READ tools.
 *
 * DESIGN RULES
 *  - **Grounded.** Every value comes from the platform's own records. A tool
 *    that cannot measure something says so; it never estimates. §19 requires
 *    that AI statements be grounded in real telemetry with no invented status.
 *  - **Secret-free by construction.** These handlers do not select credential
 *    columns at all. `ToolDispatcher` additionally scans results for
 *    secret-shaped keys, so this is belt and braces rather than the only guard.
 *  - **Scoped.** A project tool reads ONLY the project in the context. There is
 *    no argument anywhere in this class that names a project, which is the
 *    primary prompt-injection defence for the AI layer.
 *  - **No writes.** This registry is read-only and `ToolDispatcher` asserts it.
 *
 * Authorisation is NOT performed here. The dispatcher checks the acting user's
 * capability, at execution time, on the object in context, before any of these
 * methods run.
 */
final class ReadToolHandlers
{
    public function __construct(
        private readonly AiContext $context,
    ) {}

    /**
     * @return array<string, callable(array, AiContext): array>
     */
    public function map(): array
    {
        return [
            'get_platform_health' => fn (array $a): array => $this->platformHealth(),
            'list_accessible_workspaces' => fn (array $a): array => $this->accessibleWorkspaces(),
            'list_accessible_projects' => fn (array $a): array => $this->accessibleProjects($a),
            'get_infrastructure_health' => fn (array $a): array => $this->infrastructureHealth(),
            'get_resource_summary' => fn (array $a): array => $this->resourceSummary(),
            'get_failed_jobs' => fn (array $a): array => $this->failedJobs($a),
            'get_recent_errors' => fn (array $a): array => $this->recentErrors($a),
            'get_audit_events' => fn (array $a): array => $this->auditEvents($a),
            'get_connector_capabilities' => fn (array $a): array => $this->connectorCapabilities($a),

            'get_project_summary' => fn (array $a): array => $this->projectSummary(),
            'get_migration_state' => fn (array $a): array => $this->migrationState(),
            'get_cdc_status' => fn (array $a): array => $this->liveSyncStatus(),
            'get_validation_summary' => fn (array $a): array => $this->validationSummary(),
            'get_cutover_readiness' => fn (array $a): array => $this->cutoverReadiness(),
            'get_backup_status' => fn (array $a): array => $this->backupStatus(),
            'get_project_activity' => fn (array $a): array => $this->projectActivity($a),
        ];
    }

    private function cap(int $value, int $max, int $default): int
    {
        return max(1, min($max, $value > 0 ? $value : $default));
    }

    // ─────────────────────────────────────────────────────────────────
    // PLATFORM scope
    // ─────────────────────────────────────────────────────────────────

    private function platformHealth(): array
    {
        $pulse = PlatformPulse::for($this->context->access);

        return [
            'projects_total' => $pulse->projects()->count(),
            'active_migrations' => $pulse->activeMigrationCount(),
            'live_syncs' => $pulse->liveSyncCount(),
            'live_syncs_behind' => $pulse->liveSyncBehindCount(),
            'ready_for_cutover' => $pulse->readyForCutoverCount(),
            'projects_needing_attention' => $pulse->projectsNeedingAttention()->count(),
            'open_blocking_checks' => $pulse->openBlockerCount(),
            // Stated explicitly so the model cannot imply it probed the host.
            'note' => 'Counts are derived from platform records for the projects you can access.',
        ];
    }

    private function accessibleWorkspaces(): array
    {
        $workspaces = $this->context->access->accessibleWorkspaces();

        return [
            'count' => $workspaces->count(),
            'workspaces' => $workspaces->map(fn ($w): array => [
                'id' => $w->getKey(),
                'name' => $w->name,
                'kind' => $w->displayKind(),
                'status' => $w->status,
                'projects' => $w->projects()->count(),
            ])->all(),
        ];
    }

    private function accessibleProjects(array $args): array
    {
        $projects = $this->context->access->accessibleProjects();
        $limit = $this->cap((int) ($args['limit'] ?? 0), 100, 50);

        return [
            'count' => $projects->count(),
            'projects' => $projects->take($limit)->map(fn (Project $p): array => [
                'id' => $p->getKey(),
                'name' => $p->name,
                'workspace' => $p->workspace?->name,
                'environment' => $p->environment,
                'health' => $p->healthLabel(),
                'stage' => ProjectPulse::for($p)->currentStage()->label(),
                'progress_percent' => ProjectPulse::for($p)->progressPercent(),
            ])->all(),
        ];
    }

    private function infrastructureHealth(): array
    {
        try {
            $nodes = DB::table('infrastructure_nodes')
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all();
        } catch (\Throwable) {
            $nodes = [];
        }

        try {
            $services = DB::table('infrastructure_services')
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all();
        } catch (\Throwable) {
            $services = [];
        }

        return [
            'nodes_by_status' => $nodes,
            'services_by_status' => $services,
            'measured' => $nodes !== [] || $services !== [],
            'note' => $nodes === [] && $services === []
                ? 'No infrastructure nodes or services are registered, so health cannot be measured.'
                : 'Counts are from the infrastructure registry as last reported.',
        ];
    }

    private function resourceSummary(): array
    {
        try {
            // NOTE: the timestamp column is `sampled_at`, not `recorded_at`.
            $samples = DB::table('resource_metric_samples')
                ->orderByDesc('id')
                ->limit(50)
                ->get(['metric', 'value', 'unit', 'sampled_at'])
                ->groupBy('metric')
                ->map(fn ($group) => $group->first());
        } catch (\Throwable) {
            $samples = collect();
        }

        if ($samples->isEmpty()) {
            return [
                'measured' => false,
                'note' => 'No resource samples have been recorded, so CPU, memory and disk cannot be reported.',
            ];
        }

        $out = ['measured' => true];
        foreach ($samples as $metric => $sample) {
            $out[(string) $metric] = [
                'latest' => $sample->value,
                'unit' => $sample->unit,
                'at' => $sample->sampled_at,
            ];
        }

        return $out;
    }

    private function failedJobs(array $args): array
    {
        $limit = $this->cap((int) ($args['limit'] ?? 0), 50, 10);

        try {
            $jobs = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit($limit)
                ->get(['id', 'queue', 'failed_at', 'exception']);
        } catch (\Throwable) {
            return ['count' => 0, 'measured' => false, 'jobs' => [], 'note' => 'The failed-jobs table is unavailable.'];
        }

        return [
            'measured' => true,
            'count' => $jobs->count(),
            'jobs' => $jobs->map(fn ($job): array => [
                // Only the FIRST LINE of the exception: enough to identify the
                // fault, without dumping a stack trace (which can contain
                // connection details) into a prompt.
                'queue' => $job->queue,
                'failed_at' => $job->failed_at,
                'error' => mb_substr((string) strtok((string) $job->exception, "\n"), 0, 300),
            ])->all(),
        ];
    }

    private function recentErrors(array $args): array
    {
        $limit = $this->cap((int) ($args['limit'] ?? 0), 50, 10);

        try {
            $entries = DB::table('pulse_entries')
                ->whereIn('type', ['exception', 'slow_request'])
                ->orderByDesc('timestamp')
                ->limit($limit)
                ->get(['type', 'key', 'value', 'timestamp']);
        } catch (\Throwable) {
            return ['measured' => false, 'count' => 0, 'errors' => [], 'note' => 'Error telemetry is unavailable.'];
        }

        return [
            'measured' => true,
            'count' => $entries->count(),
            'errors' => $entries->map(fn ($e): array => [
                'type' => $e->type,
                // Pulse keys can embed a full URL with a query string; keep the
                // path only so a token in a query string cannot reach a model.
                'what' => mb_substr((string) strtok((string) $e->key, '?'), 0, 200),
                'at' => $e->timestamp,
            ])->all(),
        ];
    }

    private function auditEvents(array $args): array
    {
        $limit = $this->cap((int) ($args['limit'] ?? 0), 100, 20);

        try {
            $entries = DB::table('admin_audit_entries')
                ->orderByDesc('id')
                ->limit($limit)
                ->get(['action', 'project_id', 'created_at']);
        } catch (\Throwable) {
            return ['measured' => false, 'count' => 0, 'events' => []];
        }

        return [
            'measured' => true,
            'count' => $entries->count(),
            'events' => $entries->map(fn ($e): array => [
                'action' => $e->action,
                'at' => $e->created_at,
            ])->all(),
        ];
    }

    private function connectorCapabilities(array $args): array
    {
        $wanted = isset($args['connector']) ? mb_strtolower((string) $args['connector']) : null;

        try {
            $cards = ConnectorCatalogView::cards();
        } catch (\Throwable) {
            return ['measured' => false, 'connectors' => []];
        }

        $out = [];
        foreach ($cards as $card) {
            if ($wanted !== null && ! str_contains(mb_strtolower($card['key'].' '.$card['name']), $wanted)) {
                continue;
            }
            $out[] = [
                'key' => $card['key'],
                'name' => $card['name'],
                'trust' => $card['trust_label'],
                'migration' => $card['migration'],
                'live_sync' => $card['live_sync'],
                'capabilities' => array_column($card['features'], 'label'),
            ];
        }

        return ['measured' => true, 'count' => count($out), 'connectors' => $out];
    }

    // ─────────────────────────────────────────────────────────────────
    // PROJECT scope — always the project in context
    // ─────────────────────────────────────────────────────────────────

    private function project(): ?Project
    {
        return $this->context->projectTarget();
    }

    private function projectSummary(): array
    {
        $project = $this->project();
        if (! $project) {
            return ['measured' => false, 'note' => 'No project is in context.'];
        }

        $pulse = ProjectPulse::for($project);
        $overall = $pulse->overallState();

        return [
            'measured' => true,
            'name' => $project->name,
            'workspace' => $project->workspace?->name,
            'environment' => $project->environment,
            'health' => $project->healthLabel(),
            'overall_state' => $overall->label(),
            'progress_percent' => $pulse->progressPercent(),
            'current_stage' => $pulse->currentStage()->label(),
            'blocking_issues' => array_map(fn (array $b): string => $b['title'], $pulse->blockers()),
        ];
    }

    private function migrationState(): array
    {
        $project = $this->project();
        if (! $project) {
            return ['measured' => false, 'note' => 'No project is in context.'];
        }

        $pulse = ProjectPulse::for($project);

        return [
            'measured' => true,
            'progress_percent' => $pulse->progressPercent(),
            'current_stage' => $pulse->currentStage()->label(),
            'stages' => array_map(fn (array $s): array => [
                'stage' => $s['stage']->label(),
                'state' => $s['state']->label(),
                'detail' => $s['detail'],
            ], $pulse->journey()),
            'blockers' => $pulse->blockers(),
            'warnings' => $pulse->warnings(),
        ];
    }

    private function liveSyncStatus(): array
    {
        $project = $this->project();
        if (! $project) {
            return ['measured' => false, 'note' => 'No project is in context.'];
        }

        $sync = ProjectPulse::for($project)->liveSync();

        return [
            'measured' => true,
            'state' => $sync['label'],
            'detail' => $sync['detail'],
            'lag_seconds' => $sync['lagSeconds'],
            'note' => $sync['lagSeconds'] === null
                ? 'No change has been applied yet, so lag cannot be measured.'
                : 'Lag is the time since the last applied change.',
        ];
    }

    private function validationSummary(): array
    {
        $project = $this->project();
        if (! $project) {
            return ['measured' => false, 'note' => 'No project is in context.'];
        }

        $validation = CutoverReadiness::for($project)->validation();

        return [
            'measured' => true,
            'state' => $validation['label'],
            'detail' => $validation['detail'],
            'checks' => $validation['checks'],
            'failed' => $validation['failed'],
        ];
    }

    private function cutoverReadiness(): array
    {
        $project = $this->project();
        if (! $project) {
            return ['measured' => false, 'note' => 'No project is in context.'];
        }

        $readiness = CutoverReadiness::for($project);
        $overall = $readiness->overall();

        return [
            'measured' => true,
            'state' => $overall['state'],
            'label' => $overall['label'],
            'reason' => $overall['reason'],
            'blocking_issues' => $readiness->blockingIssues(),
            'gates' => array_map(fn (array $g): array => [
                'gate' => $g['gate'],
                'section' => $g['section'],
                'state' => $g['label'],
                'evidence' => $g['evidence'],
            ], $readiness->gates()),
            'approvals_outstanding' => $readiness->pendingApprovalCount(),
            'rollback' => $readiness->rollback(),
            'final_sync' => $readiness->finalSync(),
        ];
    }

    private function backupStatus(): array
    {
        $project = $this->project();
        if (! $project) {
            return ['measured' => false, 'note' => 'No project is in context.'];
        }

        $backup = CutoverReadiness::for($project)->backup();

        try {
            $last = BackupRecord::query()
                ->where('db_name', $project->db_name)
                ->orderByDesc('finished_at')
                ->first(['status', 'restore_test_status', 'finished_at', 'size_bytes', 'verified_at']);
        } catch (\Throwable) {
            $last = null;
        }

        return [
            'measured' => true,
            'label' => $backup['label'],
            'detail' => $backup['detail'],
            'verified' => $backup['verified'],
            'last_run_at' => $last?->finished_at?->toIso8601String(),
            'last_status' => $last?->status,
            'restore_tested' => $last?->restore_test_status,
            'size_bytes' => $last?->size_bytes,
        ];
    }

    private function projectActivity(array $args): array
    {
        $project = $this->project();
        if (! $project) {
            return ['measured' => false, 'note' => 'No project is in context.'];
        }

        $limit = $this->cap((int) ($args['limit'] ?? 0), 100, 20);

        try {
            $entries = DB::table('admin_audit_entries')
                ->where('project_id', $project->getKey())
                ->orderByDesc('id')
                ->limit($limit)
                ->get(['action', 'created_at']);
        } catch (\Throwable) {
            return ['measured' => false, 'count' => 0, 'events' => []];
        }

        return [
            'measured' => true,
            'count' => $entries->count(),
            'events' => $entries->map(fn ($e): array => [
                'action' => $e->action,
                'at' => $e->created_at,
            ])->all(),
        ];
    }
}
