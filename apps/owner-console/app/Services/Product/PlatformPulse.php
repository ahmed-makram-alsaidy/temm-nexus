<?php

namespace App\Services\Product;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\AdminAuditEntry;
use App\Models\BackupRecord;
use App\Models\Project;
use App\Services\Access\Access;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 0.4.0 §7 — the PLATFORM dashboard's single source of truth.
 *
 * Answers, in the order the mission specifies:
 *   1. What requires attention?
 *   2. What is running?
 *   3. What should I do next?
 *
 * SCOPE
 * Every collection here is built from `Access::accessibleProjects()`. A user
 * never sees a count that includes a project they cannot open, so the numbers
 * on Home can never imply reach the user does not have.
 *
 * COST
 * Fixed query count regardless of project count (four grouped aggregates plus
 * one project list), not one query per project. The dashboard must not become
 * slower than v0.3.0 (§35).
 */
final class PlatformPulse
{
    /** A Live Sync is "behind" past this. Mirrors ProjectPulse. */
    public const SYNC_LAG_WARNING_SECONDS = 60;

    private ?Collection $projects = null;

    private ?Collection $runsByProject = null;

    private ?Collection $checkpointsByRun = null;

    private ?Collection $openBlockingChecks = null;

    private ?Collection $cutoverReadyProjects = null;

    private ?Collection $latestBackups = null;

    public function __construct(
        private readonly Access $access,
    ) {}

    public static function for(Access $access): self
    {
        return new self($access);
    }

    // ─────────────────────────────────────────────────────────────────
    // Projects in scope
    // ─────────────────────────────────────────────────────────────────

    /** @return Collection<int, Project> */
    public function projects(): Collection
    {
        return $this->projects ??= $this->access->accessibleProjects();
    }

    /** @return list<int> */
    private function projectIds(): array
    {
        return $this->projects()->pluck('id')->all();
    }

    public function hasAnyProject(): bool
    {
        return $this->projects()->isNotEmpty();
    }

    // ─────────────────────────────────────────────────────────────────
    // Summary numbers (§7)
    // ─────────────────────────────────────────────────────────────────

    /**
     * The five summary figures Home leads with.
     *
     * @return list<array{key: string, label: string, value: int, hint: string, tone: string, url: ?string}>
     */
    public function summary(): array
    {
        $projects = $this->projects();

        return [
            [
                'key' => 'projects',
                'label' => 'Projects',
                'value' => $projects->count(),
                'hint' => $projects->where('health_status', 'healthy')->count().' healthy',
                'tone' => 'neutral',
                'url' => $this->projectsUrl(),
            ],
            [
                'key' => 'active_migrations',
                'label' => 'Active migrations',
                'value' => $this->activeMigrationCount(),
                'hint' => 'transfers running now',
                'tone' => $this->activeMigrationCount() > 0 ? 'info' : 'neutral',
                'url' => null,
            ],
            [
                'key' => 'live_syncs',
                'label' => 'Live syncs',
                'value' => $this->liveSyncCount(),
                'hint' => $this->liveSyncBehindCount() > 0
                    ? $this->liveSyncBehindCount().' behind'
                    : 'all up to date',
                'tone' => $this->liveSyncBehindCount() > 0 ? 'warning' : ($this->liveSyncCount() > 0 ? 'success' : 'neutral'),
                'url' => null,
            ],
            [
                'key' => 'ready_for_cutover',
                'label' => 'Ready for cutover',
                'value' => $this->readyForCutoverCount(),
                'hint' => $this->openBlockerCount() > 0
                    ? $this->openBlockerCount().' blocking item(s) elsewhere'
                    : 'nothing blocking',
                'tone' => $this->readyForCutoverCount() > 0 ? 'success' : 'neutral',
                'url' => null,
            ],
            [
                'key' => 'needs_attention',
                'label' => 'Needs attention',
                'value' => $this->projectsNeedingAttention()->count(),
                'hint' => 'projects to look at',
                // NOTE: deliberately NOT 'success' when zero. A green 0 on an
                // attention card reads as a celebratory metric and draws the
                // eye to the least important number on the page. Zero
                // attention is the resting state, so it renders neutral; the
                // value only takes on colour when there is something to see.
                'tone' => $this->projectsNeedingAttention()->isNotEmpty() ? 'warning' : 'neutral',
                'url' => null,
            ],
        ];
    }

    /**
     * Projects whose journey is blocked or degraded, most severe first.
     *
     * @return Collection<int, array{project: Project, state: JourneyState, reason: string, url: ?string}>
     */
    public function projectsNeedingAttention(): Collection
    {
        $out = collect();

        foreach ($this->projects() as $project) {
            $pulse = ProjectPulse::for($project);
            $state = $pulse->overallState();

            if (! in_array($state, [JourneyState::BLOCKED, JourneyState::NEEDS_ATTENTION], true)) {
                continue;
            }

            $reason = $pulse->blockers()[0]['title']
                ?? $pulse->warnings()[0]['title']
                ?? 'Something needs a look.';

            $out->push([
                'project' => $project,
                'state' => $state,
                'reason' => $reason,
                'url' => $this->projectUrl($project),
            ]);
        }

        // BLOCKED before NEEDS_ATTENTION; then by name for a stable order.
        return $out
            ->sortBy([
                fn ($a, $b) => ($a['state'] === JourneyState::BLOCKED ? 0 : 1) <=> ($b['state'] === JourneyState::BLOCKED ? 0 : 1),
                fn ($a, $b) => $a['project']->name <=> $b['project']->name,
            ])
            ->values();
    }

    public function openBlockerCount(): int
    {
        return $this->openBlockingChecks()->count();
    }

    // ─────────────────────────────────────────────────────────────────
    // Running work
    // ─────────────────────────────────────────────────────────────────

    public function activeMigrationCount(): int
    {
        return $this->activeRuns()->count();
    }

    /** @return Collection<int, object> */
    public function activeRuns(): Collection
    {
        return $this->runsByProject()
            ->flatten(1)
            ->filter(fn ($run): bool => in_array(strtolower((string) $run->status), ['running', 'in_progress', 'started', 'applying'], true))
            ->values();
    }

    public function liveSyncCount(): int
    {
        return $this->checkpoints()->count();
    }

    public function liveSyncBehindCount(): int
    {
        return $this->checkpoints()
            ->filter(fn ($cp): bool => $this->checkpointLagSeconds($cp) >= self::SYNC_LAG_WARNING_SECONDS)
            ->count();
    }

    public function readyForCutoverCount(): int
    {
        return $this->readyForCutoverProjects()->count();
    }

    /**
     * Projects whose journey has reached READY at the Cutover stage.
     *
     * @return Collection<int, Project>
     */
    public function readyForCutoverProjects(): Collection
    {
        return $this->cutoverReadyProjects ??= $this->projects()
            ->filter(function (Project $project): bool {
                $pulse = ProjectPulse::for($project);

                return $pulse->stageState(JourneyStage::CUTOVER) === JourneyState::READY
                    && $pulse->blockers() === [];
            })
            ->values();
    }

    // ─────────────────────────────────────────────────────────────────
    // Recent activity
    // ─────────────────────────────────────────────────────────────────

    /**
     * Recent audit entries, scoped to projects the user can reach.
     *
     * @return Collection<int, array{at: ?CarbonInterface, action: string, project: ?string, actor: ?string}>
     */
    public function recentActivity(int $limit = 8): Collection
    {
        $ids = $this->projectIds();
        if ($ids === []) {
            return collect();
        }

        try {
            $entries = AdminAuditEntry::query()
                ->whereIn('project_id', $ids)
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        } catch (\Throwable) {
            return collect();
        }

        $names = $this->projects()->pluck('name', 'id');

        return $entries->map(fn ($entry): array => [
            'at' => $entry->created_at,
            'action' => (string) $entry->action,
            'project' => $entry->project_id ? ($names[$entry->project_id] ?? null) : null,
            'actor' => null,
        ])->values();
    }

    // ─────────────────────────────────────────────────────────────────
    // Infrastructure (deliberately LAST — §7)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Backup health across the installation, in product language.
     *
     * @return array{taken: int, stale: int, unverified: int, never: int, last: ?string}
     */
    public function backupSummary(): array
    {
        $backups = $this->latestBackups();
        $names = $this->projects()->pluck('db_name')->filter()->all();

        if ($names === []) {
            return ['taken' => 0, 'stale' => 0, 'unverified' => 0, 'never' => 0, 'last' => null];
        }

        $stale = 0;
        $unverified = 0;
        $taken = 0;
        $last = null;

        foreach ($names as $dbName) {
            $record = $backups->get($dbName);
            if (! $record) {
                continue;
            }
            $taken++;
            if ($record->finished_at && $record->finished_at->lt(now()->subHours(ProjectPulse::BACKUP_STALE_HOURS))) {
                $stale++;
            }
            if ($record->restore_test_status !== 'verified') {
                $unverified++;
            }
            if ($last === null || ($record->finished_at && $record->finished_at->gt($last))) {
                $last = $record->finished_at;
            }
        }

        return [
            'taken' => $taken,
            'stale' => $stale,
            'unverified' => $unverified,
            'never' => max(0, count($names) - $taken),
            'last' => $last?->diffForHumans(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Data access — fixed query count, no N+1
    // ─────────────────────────────────────────────────────────────────

    /** @return Collection<int, Collection> keyed by project_id */
    private function runsByProject(): Collection
    {
        if ($this->runsByProject !== null) {
            return $this->runsByProject;
        }

        $ids = $this->projectIds();
        if ($ids === []) {
            return $this->runsByProject = collect();
        }

        try {
            $runs = DB::table('migration_runs')
                ->whereIn('project_id', $ids)
                ->orderByDesc('id')
                ->get(['id', 'project_id', 'status', 'mode', 'started_at', 'finished_at']);
        } catch (\Throwable) {
            return $this->runsByProject = collect();
        }

        // Only the newest run per project matters for "what is running".
        return $this->runsByProject = $runs
            ->groupBy('project_id')
            ->map(fn (Collection $group): Collection => $group->take(1)->values());
    }

    /** @return Collection<int, object> newest checkpoint per project */
    private function checkpoints(): Collection
    {
        if ($this->checkpointsByRun !== null) {
            return $this->checkpointsByRun;
        }

        $runIds = $this->runsByProject()->flatten(1)->pluck('id')->all();
        if ($runIds === []) {
            return $this->checkpointsByRun = collect();
        }

        try {
            $rows = DB::table('cdc_checkpoints')
                ->whereIn('migration_run_id', $runIds)
                ->orderByDesc('id')
                ->get(['id', 'migration_run_id', 'stream_status', 'last_event_at', 'applied_events', 'lag_events']);
        } catch (\Throwable) {
            return $this->checkpointsByRun = collect();
        }

        return $this->checkpointsByRun = $rows
            ->groupBy('migration_run_id')
            ->map(fn (Collection $group) => $group->first())
            ->values();
    }

    private function checkpointLagSeconds(object $checkpoint): int
    {
        if (! $checkpoint->last_event_at) {
            return 0; // Never started is not "behind".
        }

        try {
            return max(0, now()->diffInSeconds(Carbon::parse($checkpoint->last_event_at), false));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function openBlockingChecks(): Collection
    {
        if ($this->openBlockingChecks !== null) {
            return $this->openBlockingChecks;
        }

        $ids = $this->projectIds();
        if ($ids === []) {
            return $this->openBlockingChecks = collect();
        }

        try {
            return $this->openBlockingChecks = DB::table('readiness_checks')
                ->whereIn('project_id', $ids)
                ->where('blocks_production', true)
                ->whereNull('acknowledged_at')
                ->whereIn('status', ['failed', 'warning'])
                ->get(['id', 'project_id', 'title', 'status']);
        } catch (\Throwable) {
            return $this->openBlockingChecks = collect();
        }
    }

    /** @return Collection<string, BackupRecord> keyed by db_name */
    private function latestBackups(): Collection
    {
        if ($this->latestBackups !== null) {
            return $this->latestBackups;
        }

        $names = $this->projects()->pluck('db_name')->filter()->unique()->all();
        if ($names === []) {
            return $this->latestBackups = collect();
        }

        try {
            $records = BackupRecord::query()
                ->whereIn('db_name', $names)
                ->orderByDesc('finished_at')
                ->get();
        } catch (\Throwable) {
            return $this->latestBackups = collect();
        }

        return $this->latestBackups = $records->unique('db_name')->keyBy('db_name');
    }

    private function projectsUrl(): ?string
    {
        try {
            return ProjectResource::getUrl('index');
        } catch (\Throwable) {
            return null;
        }
    }

    private function projectUrl(Project $project): ?string
    {
        try {
            return ProjectResource::getUrl('overview', ['record' => $project]);
        } catch (\Throwable) {
            return null;
        }
    }
}
