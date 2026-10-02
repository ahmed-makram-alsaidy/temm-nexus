<?php

namespace App\Services\Product;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\BackupRecord;
use App\Models\CutoverPlan;
use App\Models\MigrationAnalysis;
use App\Models\MigrationPlan;
use App\Models\MigrationRun;
use App\Models\MigrationSource;
use App\Models\Project;
use App\Models\ReadinessCheck;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 0.4.0 §6/§9 — derives the project's migration journey from real telemetry.
 *
 * WHY THIS EXISTS
 * v0.3.0 exposed raw subsystem status (`draft`, `paused`, `unknown`) scattered
 * across a dozen pages with nothing tying them together, so a user could not
 * answer "where am I, what is blocked, what is next". This service answers
 * those questions once, from the platform's own records, and every 0.4.0
 * surface reads it rather than re-deriving state.
 *
 * Every query is scoped to ONE project id that the caller has already been
 * authorised for — this class performs no authorisation of its own and must
 * never be handed a project id from a request without a prior reach check.
 *
 * Nothing here is cached across requests, and nothing is inferred from a
 * missing table: an absent subsystem reports NOT_STARTED, never a false green.
 */
final class ProjectPulse
{
    /** A Live Sync is "behind" past this many seconds since the last event. */
    public const SYNC_LAG_WARNING_SECONDS = 60;

    /** A Live Sync is materially broken past this. */
    public const SYNC_LAG_BLOCKING_SECONDS = 600;

    /** A backup older than this raises attention. */
    public const BACKUP_STALE_HOURS = 26;

    public function __construct(
        private readonly Project $project,
    ) {}

    public static function for(Project $project): self
    {
        return new self($project);
    }

    // ─────────────────────────────────────────────────────────────────
    // Stage resolution
    // ─────────────────────────────────────────────────────────────────

    /**
     * The journey, stage by stage.
     *
     * @return list<array{stage: JourneyStage, state: JourneyState, detail: string, url: ?string}>
     */
    public function journey(): array
    {
        $out = [];
        foreach (JourneyStage::sequence() as $stage) {
            $out[] = [
                'stage' => $stage,
                'state' => $this->stageState($stage),
                'detail' => $this->stageDetail($stage),
                'url' => $this->stageUrl($stage),
            ];
        }

        return $out;
    }

    /** The furthest stage the project has genuinely reached. */
    public function currentStage(): JourneyStage
    {
        $reached = JourneyStage::CONNECT;
        foreach (JourneyStage::sequence() as $stage) {
            $state = $this->stageState($stage);
            if ($state === JourneyState::COMPLETE || $state === JourneyState::READY) {
                $reached = $stage;
            } elseif ($state === JourneyState::IN_PROGRESS) {
                return $stage;
            }
        }

        return $reached;
    }

    /**
     * Progress as a whole percentage.
     *
     * Settled stages score 1, an in-progress stage scores 0.5, and everything
     * else scores 0. Deliberately conservative: a stage is not "half done"
     * unless the platform has evidence it started.
     */
    public function progressPercent(): int
    {
        $score = 0.0;
        foreach (JourneyStage::sequence() as $stage) {
            $score += match ($this->stageState($stage)) {
                JourneyState::COMPLETE, JourneyState::READY => 1.0,
                JourneyState::IN_PROGRESS => 0.5,
                default => 0.0,
            };
        }

        return (int) round(100 * $score / count(JourneyStage::sequence()));
    }

    public function stageState(JourneyStage $stage): JourneyState
    {
        return match ($stage) {
            JourneyStage::CONNECT => $this->connectState(),
            JourneyStage::ANALYZE => $this->analyzeState(),
            JourneyStage::PLAN => $this->planState(),
            JourneyStage::MIGRATE => $this->migrateState(),
            JourneyStage::SYNC => $this->syncState(),
            JourneyStage::VALIDATE => $this->validateState(),
            JourneyStage::CUTOVER => $this->cutoverState(),
        };
    }

    private function stageDetail(JourneyStage $stage): string
    {
        return match ($stage) {
            JourneyStage::CONNECT => $this->sources()->isEmpty()
                ? 'No source connected yet.'
                : $this->sources()->count().' source(s) connected.',
            JourneyStage::ANALYZE => $this->latestAnalysis()
                ? 'Last analyzed '.$this->latestAnalysis()->updated_at?->diffForHumans().'.'
                : 'Not analyzed yet.',
            JourneyStage::PLAN => $this->latestPlan()
                ? 'Plan prepared '.$this->latestPlan()->created_at?->diffForHumans().'.'
                : 'No plan yet.',
            JourneyStage::MIGRATE => $this->runs()->isEmpty()
                ? 'No transfer run yet.'
                : $this->runs()->count().' run(s), latest is '.JourneyState::fromRaw($this->latestRun()?->status)->label().'.',
            JourneyStage::SYNC => $this->syncDetail(),
            JourneyStage::VALIDATE => $this->validateDetail(),
            JourneyStage::CUTOVER => $this->cutoverDetail(),
        };
    }

    private function stageUrl(JourneyStage $stage): ?string
    {
        $page = match ($stage) {
            JourneyStage::CONNECT => 'connect',
            JourneyStage::ANALYZE, JourneyStage::PLAN, JourneyStage::MIGRATE => 'migration-center',
            JourneyStage::SYNC => 'monitoring',
            // 0.4.0 Phase E: Cutover is its own destination now. Validation still
            // points at Readiness; Cutover no longer does.
            JourneyStage::VALIDATE => 'readiness',
            JourneyStage::CUTOVER => 'cutover',
        };

        try {
            // Fall back to the overview rather than emit a dead link if the
            // destination is not registered in this build.
            if (! ProjectResource::hasPage($page)) {
                $page = 'overview';
            }

            return ProjectResource::getUrl($page, ['record' => $this->project]);
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Individual stages ──────────────────────────────────────────────

    private function connectState(): JourneyState
    {
        $sources = $this->sources();
        if ($sources->isEmpty()) {
            return JourneyState::NOT_STARTED;
        }

        // A source that failed its last validation needs attention.
        foreach ($sources as $source) {
            if (in_array(strtolower((string) $source->status), ['failed', 'error'], true)) {
                return JourneyState::NEEDS_ATTENTION;
            }
        }

        return JourneyState::COMPLETE;
    }

    private function analyzeState(): JourneyState
    {
        $analysis = $this->latestAnalysis();
        if (! $analysis) {
            return JourneyState::NOT_STARTED;
        }

        return JourneyState::fromRaw($analysis->status ?? null) === JourneyState::BLOCKED
            ? JourneyState::NEEDS_ATTENTION
            : JourneyState::COMPLETE;
    }

    private function planState(): JourneyState
    {
        $plan = $this->latestPlan();
        if (! $plan) {
            return $this->latestAnalysis() ? JourneyState::IN_PROGRESS : JourneyState::NOT_STARTED;
        }

        return JourneyState::COMPLETE;
    }

    private function migrateState(): JourneyState
    {
        $run = $this->latestRun();
        if (! $run) {
            return $this->latestPlan() ? JourneyState::IN_PROGRESS : JourneyState::NOT_STARTED;
        }

        return JourneyState::fromRaw($run->status);
    }

    private function syncState(): JourneyState
    {
        $checkpoint = $this->checkpoint();
        if (! $checkpoint) {
            // Live Sync only becomes meaningful once a transfer has happened.
            return $this->migrateState() === JourneyState::COMPLETE
                ? JourneyState::IN_PROGRESS
                : JourneyState::NOT_STARTED;
        }

        $status = strtolower((string) $checkpoint->stream_status);

        if (in_array($status, ['failed', 'error', 'stopped'], true)) {
            return JourneyState::BLOCKED;
        }

        $lag = $this->syncLagSeconds();
        if ($lag !== null && $lag >= self::SYNC_LAG_BLOCKING_SECONDS) {
            return JourneyState::BLOCKED;
        }
        if ($lag !== null && $lag >= self::SYNC_LAG_WARNING_SECONDS) {
            return JourneyState::NEEDS_ATTENTION;
        }
        if (in_array($status, ['paused', 'lagging', 'stale', 'degraded'], true)) {
            return JourneyState::NEEDS_ATTENTION;
        }

        return $status === 'streaming' || $status === 'active'
            ? JourneyState::COMPLETE
            : JourneyState::IN_PROGRESS;
    }

    private function validateState(): JourneyState
    {
        $checks = $this->readinessChecks();
        if ($checks->isEmpty()) {
            return $this->syncState()->isSettled() ? JourneyState::IN_PROGRESS : JourneyState::NOT_STARTED;
        }

        if ($checks->where('status', 'failed')->isNotEmpty()) {
            return JourneyState::BLOCKED;
        }
        if ($checks->where('status', 'warning')->isNotEmpty()) {
            return JourneyState::NEEDS_ATTENTION;
        }

        return JourneyState::COMPLETE;
    }

    private function cutoverState(): JourneyState
    {
        // FAILED CHECKS OUTRANK EVERYTHING, including "there is no plan yet".
        //
        // An earlier version only consulted these checks once a cutover plan
        // existed, so a project with failing validation and no plan reported
        // NOT_STARTED — the single most dangerous thing this service could say,
        // because it presents a blocked project as one that simply has not
        // begun. §10 requires the block to be visible and explained.
        $validation = $this->validateState();
        if ($validation === JourneyState::BLOCKED) {
            return JourneyState::BLOCKED;
        }

        $plan = $this->cutoverPlan();

        if (! $plan) {
            return match ($validation) {
                JourneyState::NEEDS_ATTENTION => JourneyState::NEEDS_ATTENTION,
                JourneyState::COMPLETE => JourneyState::IN_PROGRESS,
                default => JourneyState::NOT_STARTED,
            };
        }

        // A plan exists: any unacknowledged blocking check stops the cutover.
        if ($this->blockingChecks()->isNotEmpty()) {
            return JourneyState::BLOCKED;
        }

        if ($validation === JourneyState::NEEDS_ATTENTION) {
            return JourneyState::NEEDS_ATTENTION;
        }

        return JourneyState::fromRaw($plan->status) === JourneyState::COMPLETE
            ? JourneyState::COMPLETE
            : JourneyState::READY;
    }

    // ─────────────────────────────────────────────────────────────────
    // Overall state + blockers
    // ─────────────────────────────────────────────────────────────────

    /**
     * The single word for the project: the most severe state on the journey.
     *
     * Severity order is deliberate — BLOCKED outranks everything, because a
     * project that is blocked must never be summarised as "in progress".
     */
    public function overallState(): JourneyState
    {
        if ($this->project->health_status === 'unhealthy') {
            return JourneyState::BLOCKED;
        }

        $states = array_map(fn (array $s): JourneyState => $s['state'], $this->journey());

        foreach ([JourneyState::BLOCKED, JourneyState::NEEDS_ATTENTION, JourneyState::IN_PROGRESS, JourneyState::READY] as $candidate) {
            if (in_array($candidate, $states, true)) {
                return $candidate;
            }
        }

        return in_array(JourneyState::COMPLETE, $states, true)
            ? JourneyState::COMPLETE
            : JourneyState::NOT_STARTED;
    }

    /**
     * Everything stopping progress, each with a reason (§10).
     *
     * @return list<array{severity: string, title: string, detail: string, url: ?string}>
     */
    public function blockers(): array
    {
        $out = [];

        foreach ($this->blockingChecks() as $check) {
            $out[] = [
                'severity' => 'danger',
                'title' => (string) ($check->title ?: $check->check_key),
                'detail' => (string) ($check->detail ?: 'This check blocks production cutover.'),
                'url' => $this->stageUrl(JourneyStage::CUTOVER),
            ];
        }

        $run = $this->latestRun();
        if ($run && JourneyState::fromRaw($run->status) === JourneyState::BLOCKED) {
            $out[] = [
                'severity' => 'danger',
                'title' => 'The last transfer run failed',
                'detail' => (string) ($run->failure ? mb_substr($run->failure, 0, 200) : 'Review the run for the cause.'),
                'url' => $this->stageUrl(JourneyStage::MIGRATE),
            ];
        }

        $lag = $this->syncLagSeconds();
        if ($lag !== null && $lag >= self::SYNC_LAG_BLOCKING_SECONDS) {
            $out[] = [
                'severity' => 'danger',
                'title' => 'Live Sync is far behind',
                'detail' => 'The last change was applied '.$this->humanDuration($lag).' ago. Cutover is unsafe until it catches up.',
                'url' => $this->stageUrl(JourneyStage::SYNC),
            ];
        }

        return $out;
    }

    /**
     * Things that are degraded but not stopping (§10 WARNING).
     *
     * @return list<array{severity: string, title: string, detail: string, url: ?string}>
     */
    public function warnings(): array
    {
        $out = [];

        $lag = $this->syncLagSeconds();
        if ($lag !== null && $lag >= self::SYNC_LAG_WARNING_SECONDS && $lag < self::SYNC_LAG_BLOCKING_SECONDS) {
            $out[] = [
                'severity' => 'warning',
                'title' => 'Live Sync is behind',
                'detail' => 'Last change applied '.$this->humanDuration($lag).' ago.',
                'url' => $this->stageUrl(JourneyStage::SYNC),
            ];
        }

        if ($this->project->health_status === 'unknown') {
            $out[] = [
                'severity' => 'warning',
                'title' => 'No health result yet',
                'detail' => 'Run a health check to get a conclusive status.',
                'url' => $this->stageUrl(JourneyStage::VALIDATE),
            ];
        }

        $backup = $this->latestBackup();
        if (! $backup) {
            $out[] = [
                'severity' => 'warning',
                'title' => 'No backup recorded',
                'detail' => 'Cutover needs a verified backup to be reversible.',
                'url' => null,
            ];
        } elseif ($backup->finished_at && $backup->finished_at->lt(now()->subHours(self::BACKUP_STALE_HOURS))) {
            $out[] = [
                'severity' => 'warning',
                'title' => 'The last backup is old',
                'detail' => 'Taken '.$backup->finished_at->diffForHumans().'.',
                'url' => null,
            ];
        } elseif ($backup->restore_test_status !== 'verified') {
            $out[] = [
                'severity' => 'warning',
                'title' => 'The last backup was never restore-tested',
                'detail' => 'A backup you have not restored is not yet proven.',
                'url' => null,
            ];
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────
    // Headline facts
    // ─────────────────────────────────────────────────────────────────

    /**
     * Live Sync in product language (§14).
     *
     * @return array{state: JourneyState, label: string, detail: string, lagSeconds: ?int}
     */
    public function liveSync(): array
    {
        $state = $this->syncState();

        return [
            'state' => $state,
            'label' => match ($state) {
                JourneyState::COMPLETE => 'Up to date',
                JourneyState::IN_PROGRESS => 'Starting',
                JourneyState::NEEDS_ATTENTION => 'Behind',
                JourneyState::BLOCKED => 'Stopped',
                default => 'Not running',
            },
            'detail' => $this->syncDetail(),
            'lagSeconds' => $this->syncLagSeconds(),
        ];
    }

    private function syncDetail(): string
    {
        $checkpoint = $this->checkpoint();
        if (! $checkpoint) {
            return 'Live Sync has not started.';
        }

        $lag = $this->syncLagSeconds();
        if ($lag === null) {
            return 'No change has been applied yet.';
        }

        return 'Last synced '.$this->humanDuration($lag).' ago.';
    }

    private function validateDetail(): string
    {
        $checks = $this->readinessChecks();
        if ($checks->isEmpty()) {
            return 'No validation has run yet.';
        }

        $failed = $checks->where('status', 'failed')->count();
        $warned = $checks->where('status', 'warning')->count();

        if ($failed > 0) {
            return $failed.' check(s) failed.';
        }
        if ($warned > 0) {
            return $warned.' check(s) need review.';
        }

        return 'All '.$checks->count().' check(s) pass.';
    }

    private function cutoverDetail(): string
    {
        $plan = $this->cutoverPlan();
        if (! $plan) {
            return 'No cutover plan yet.';
        }

        $blocking = $this->blockingChecks()->count();
        if ($blocking > 0) {
            return $blocking.' item(s) still block cutover.';
        }

        return 'Plan status: '.JourneyState::fromRaw($plan->status)->label().'.';
    }

    public function syncLagSeconds(): ?int
    {
        $checkpoint = $this->checkpoint();
        if (! $checkpoint || ! $checkpoint->last_event_at) {
            return null;
        }

        return max(0, (int) $checkpoint->last_event_at->diffInSeconds(now(), false));
    }

    /**
     * Backup in product language.
     *
     * @return array{state: JourneyState, label: string, detail: string}
     */
    public function backup(): array
    {
        $backup = $this->latestBackup();

        if (! $backup) {
            return ['state' => JourneyState::NOT_STARTED, 'label' => 'Never', 'detail' => 'No backup has been taken.'];
        }

        $state = match (true) {
            in_array($backup->status, ['failed', 'error'], true) => JourneyState::BLOCKED,
            $backup->status === 'running' => JourneyState::IN_PROGRESS,
            $backup->restore_test_status !== 'verified' => JourneyState::NEEDS_ATTENTION,
            default => JourneyState::COMPLETE,
        };

        return [
            'state' => $state,
            'label' => $backup->finished_at?->diffForHumans() ?? ucfirst((string) $backup->status),
            'detail' => $backup->restore_test_status === 'verified'
                ? 'Restore verified.'
                : 'Not restore-tested.',
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Data access — small, project-scoped, tolerant of a missing table
    // ─────────────────────────────────────────────────────────────────

    private function sources(): Collection
    {
        return $this->safe(
            fn () => MigrationSource::query()->where('project_id', $this->project->id)->get(),
            new Collection,
        );
    }

    private function latestAnalysis(): ?MigrationAnalysis
    {
        return $this->safe(fn () => MigrationAnalysis::query()
            ->where('project_id', $this->project->id)
            ->latest('id')
            ->first());
    }

    private function latestPlan(): ?MigrationPlan
    {
        return $this->safe(fn () => MigrationPlan::query()
            ->where('project_id', $this->project->id)
            ->latest('id')
            ->first());
    }

    private function runs(): Collection
    {
        return $this->safe(
            fn () => MigrationRun::query()
                ->where('project_id', $this->project->id)
                ->latest('id')
                ->get(),
            new Collection,
        );
    }

    private function latestRun(): ?MigrationRun
    {
        return $this->runs()->first();
    }

    private function checkpoint(): ?object
    {
        return $this->safe(function () {
            $runIds = $this->runs()->pluck('id');
            if ($runIds->isEmpty()) {
                return null;
            }

            return DB::table('cdc_checkpoints')
                ->whereIn('migration_run_id', $runIds)
                ->orderByDesc('id')
                ->first();
        });
    }

    private function readinessChecks(): Collection
    {
        return $this->safe(
            fn () => ReadinessCheck::query()->where('project_id', $this->project->id)->get(),
            new Collection,
        );
    }

    private function blockingChecks(): Collection
    {
        return $this->readinessChecks()
            ->filter(fn ($check): bool => (bool) $check->blocks_production
                && $check->acknowledged_at === null
                && in_array($check->status, ['failed', 'warning'], true))
            ->values();
    }

    private function cutoverPlan(): ?CutoverPlan
    {
        return $this->safe(fn () => CutoverPlan::query()
            ->where('project_id', $this->project->id)
            ->latest('id')
            ->first());
    }

    private function latestBackup(): ?BackupRecord
    {
        return $this->safe(fn () => BackupRecord::query()
            ->where('db_name', $this->project->db_name)
            ->latest('finished_at')
            ->first());
    }

    /**
     * Run a query, returning a caller-supplied fallback if the subsystem is
     * absent or the query fails.
     *
     * IMPORTANT: the fallback must match the caller's declared return type.
     * An earlier version always returned an empty Collection, which both
     * violated the `?Model` signatures AND hid the resulting type error behind
     * a 500. A failure here degrades one figure on one screen; it must never
     * be converted into a false success, and it must never mask a bug.
     */
    private function safe(callable $query, mixed $fallback = null): mixed
    {
        try {
            $result = $query();

            return $result ?? $fallback;
        } catch (\Throwable $e) {
            // Log at debug so a schema drift is diagnosable without turning a
            // missing optional subsystem into a page-level error.
            Log::debug('ProjectPulse query degraded', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    public function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' second'.($seconds === 1 ? '' : 's');
        }
        if ($seconds < 3600) {
            $m = intdiv($seconds, 60);

            return $m.' minute'.($m === 1 ? '' : 's');
        }
        if ($seconds < 86400) {
            $h = intdiv($seconds, 3600);

            return $h.' hour'.($h === 1 ? '' : 's');
        }

        $d = intdiv($seconds, 86400);

        return $d.' day'.($d === 1 ? '' : 's');
    }
}
