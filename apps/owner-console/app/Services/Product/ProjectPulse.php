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
use App\Services\ControlPlane\Connectors\ConnectorCapability;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Support\ProductStatus;
use Illuminate\Support\Carbon;
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

    /** 0.6.0 Phase C — per-instance read-through caches (§C performance).
     * One Home render composes several sections over the same project;
     * without these, each journey() recomputation re-queried every
     * subsystem. Nothing is cached ACROSS requests. */
    private ?Collection $sourcesCache = null;

    /**
     * 0.6.0 Phase D — batch seed used by the Projects index (§D13).
     * When set, the pulse renders from caller-supplied snapshots instead of
     * issuing its own point queries; anything NOT in the snapshot still
     * lazily queries exactly as before, so state can never differ.
     */
    private bool $preloaded = false;

    private ?Collection $runsCache = null;

    private ?Collection $checksCache = null;

    private ?MigrationAnalysis $analysisCache = null;

    private bool $analysisResolved = false;

    private ?MigrationPlan $planCache = null;

    private bool $planResolved = false;

    private ?CutoverPlan $cutoverPlanCache = null;

    private bool $cutoverPlanResolved = false;

    private ?BackupRecord $backupCache = null;

    private bool $backupResolved = false;

    private ?object $checkpointCache = null;

    private bool $checkpointResolved = false;

    public function __construct(
        private readonly Project $project,
    ) {}

    public static function for(Project $project): self
    {
        return new self($project);
    }

    /**
     * 0.6.0 Phase D (§D13) — seed this pulse from a caller's BATCH queries so
     * one page can derive many projects' journeys without per-project reads.
     *
     * Snapshot keys (all optional): `sources` (Collection), `analysis`
     * (?MigrationAnalysis), `plan` (?MigrationPlan), `runs` (Collection,
     * newest-first — see the count caveat below), `checkpoint` (?object),
     * `checks` (Collection), `cutoverPlan` (?CutoverPlan), `backup`
     * (?BackupRecord).
     *
     * CAVEAT: `runs` is the newest run per project ONLY. Stage states,
     * progress and attention are identical to the lazy path; a consumer that
     * renders the full run count in stage detail sentences must use a lazy
     * pulse (`ProjectPulse::for()`), as Project Overview does.
     */
    public function preload(array $snapshot): void
    {
        if (array_key_exists('sources', $snapshot)) {
            $this->sourcesCache = $snapshot['sources'] ?? new Collection;
        }
        if (array_key_exists('analysis', $snapshot)) {
            $this->analysisCache = $snapshot['analysis'];
            $this->analysisResolved = true;
        }
        if (array_key_exists('plan', $snapshot)) {
            $this->planCache = $snapshot['plan'];
            $this->planResolved = true;
        }
        if (array_key_exists('runs', $snapshot)) {
            $this->runsCache = $snapshot['runs'] ?? new Collection;
        }
        if (array_key_exists('checkpoint', $snapshot)) {
            $this->checkpointCache = $snapshot['checkpoint'];
            $this->checkpointResolved = true;
        }
        if (array_key_exists('checks', $snapshot)) {
            $this->checksCache = $snapshot['checks'] ?? new Collection;
        }
        if (array_key_exists('cutoverPlan', $snapshot)) {
            $this->cutoverPlanCache = $snapshot['cutoverPlan'];
            $this->cutoverPlanResolved = true;
        }
        if (array_key_exists('backup', $snapshot)) {
            $this->backupCache = $snapshot['backup'];
            $this->backupResolved = true;
        }

        $this->preloaded = true;
    }

    /** True when this pulse renders from a caller-supplied batch snapshot. */
    public function isPreloaded(): bool
    {
        return $this->preloaded;
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

    /**
     * 0.6.0 Phase D (§D10) — stage details speak the product language, through
     * translations, with structured counts passed as parameters. The stored
     * domain state is never translated text.
     */
    private function stageDetail(JourneyStage $stage): string
    {
        return match ($stage) {
            JourneyStage::CONNECT => $this->sources()->isEmpty()
                ? __('projects.stage_connect_none')
                : (string) trans_choice('projects.stage_connect_count', $this->sources()->count(), ['count' => $this->sources()->count()]),
            JourneyStage::ANALYZE => $this->latestAnalysis()
                ? __('projects.stage_analyze_done', ['when' => $this->latestAnalysis()->updated_at?->diffForHumans()])
                : __('projects.stage_analyze_none'),
            JourneyStage::PLAN => $this->latestPlan()
                ? __('projects.stage_plan_done', ['when' => $this->latestPlan()->created_at?->diffForHumans()])
                : __('projects.stage_plan_none'),
            JourneyStage::MIGRATE => $this->runs()->isEmpty()
                ? __('projects.stage_migrate_none')
                : (string) trans_choice('projects.stage_migrate_count', $this->runs()->count(), [
                    'count' => $this->runs()->count(),
                    'state' => JourneyState::fromRaw($this->latestRun()?->status)->label(),
                ]),
            JourneyStage::SYNC => $this->syncDetail(),
            JourneyStage::VALIDATE => $this->validateDetail(),
            JourneyStage::CUTOVER => $this->cutoverDetail(),
        };
    }

    /**
     * 0.6.0 Phase C — public access to the canonical journey→URL mapping.
     * Home's "Continue where you left off" reuses THIS mapping (§C14: one
     * interpretation layer) instead of deriving its own destinations.
     */
    public function urlForStage(JourneyStage $stage): ?string
    {
        return $this->stageUrl($stage);
    }

    private function stageUrl(JourneyStage $stage): ?string
    {
        // 0.6.0 Phase E (§E12) — every stage lands ON the Migration journey
        // page's matching stage tab. The absorbed pages (Migration Center,
        // Cutover, Readiness) keep their routes as deep links; the journey is
        // the canonical destination.
        $target = match ($stage) {
            JourneyStage::CONNECT => ['migration', 'connect'],
            JourneyStage::ANALYZE => ['migration', 'analyze'],
            JourneyStage::PLAN => ['migration', 'plan'],
            JourneyStage::MIGRATE, JourneyStage::SYNC => ['migration', 'sync'],
            JourneyStage::VALIDATE => ['migration', 'verify'],
            JourneyStage::CUTOVER => ['migration', 'cutover'],
        };
        [$page, $productStage] = $target;

        try {
            // Fall back to the overview rather than emit a dead link if the
            // destination is not registered in this build.
            if (! ProjectResource::hasPage($page)) {
                return ProjectResource::getUrl('overview', ['record' => $this->project]);
            }

            return ProjectResource::getUrl($page, ['record' => $this->project, 'stage' => $productStage]);
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
            // 0.6.1 — with no checkpoint, IN_PROGRESS ("Starting") is only
            // honest when Live Sync could actually run. Two gates:
            //
            //  1. The source connector must declare change capture at all.
            //     The wizard Review already says "Live Sync — Not supported
            //     by this connector" for such sources; the journey must agree
            //     with the Review, never contradict it with a permanent fake
            //     "Starting" (a state this connector can never leave).
            //  2. A transfer must actually have happened. A dry run writes
            //     nothing — the run executor never even connects the target —
            //     so it cannot hand off to Live Sync; only a rehearsal/real
            //     run can.
            if (! $this->liveSyncSupported()) {
                return JourneyState::NOT_STARTED;
            }

            return $this->latestRunTransferred() && $this->migrateState() === JourneyState::COMPLETE
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
     * 0.6.0 Phase D (§D5): titles and details are translated at the last
     * moment — no raw SQLSTATE, capability slug or audit verb ever reaches a
     * product surface from here. The check's own stored title (human data)
     * stays when present.
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
                'detail' => (string) ($check->detail ?: __('projects.problem_check_blocks')),
                'url' => $this->stageUrl(JourneyStage::CUTOVER),
            ];
        }

        $run = $this->latestRun();
        if ($run && JourneyState::fromRaw($run->status) === JourneyState::BLOCKED) {
            $out[] = [
                'severity' => 'danger',
                // §D5: the failure payload may contain raw SQLSTATE/exception
                // text — it stays in the run's own detail views, never here.
                'title' => __('projects.problem_run_failed_title'),
                'detail' => __('projects.problem_run_failed_detail'),
                'url' => $this->stageUrl(JourneyStage::MIGRATE),
            ];
        }

        $lag = $this->syncLagSeconds();
        if ($lag !== null && $lag >= self::SYNC_LAG_BLOCKING_SECONDS) {
            $out[] = [
                'severity' => 'danger',
                'title' => __('projects.problem_sync_far_title'),
                'detail' => __('projects.problem_sync_far_detail', ['when' => $this->durationAgo($lag)]),
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
                'title' => __('projects.problem_sync_behind_title'),
                'detail' => __('projects.problem_sync_behind_detail', ['when' => $this->durationAgo($lag)]),
                'url' => $this->stageUrl(JourneyStage::SYNC),
            ];
        }

        if ($this->project->health_status === 'unknown') {
            $out[] = [
                'severity' => 'warning',
                'title' => __('projects.problem_health_unknown_title'),
                'detail' => __('projects.problem_health_unknown_detail'),
                'url' => $this->stageUrl(JourneyStage::VALIDATE),
            ];
        }

        $backup = $this->latestBackup();
        if (! $backup) {
            $out[] = [
                'severity' => 'warning',
                'title' => __('projects.problem_backup_missing_title'),
                'detail' => __('projects.problem_backup_missing_detail'),
                'url' => null,
            ];
        } elseif ($backup->finished_at && $backup->finished_at->lt(now()->subHours(self::BACKUP_STALE_HOURS))) {
            $out[] = [
                'severity' => 'warning',
                'title' => __('projects.problem_backup_stale_title'),
                'detail' => __('projects.problem_backup_stale_detail', ['when' => $backup->finished_at->diffForHumans()]),
                'url' => null,
            ];
        } elseif ($backup->restore_test_status !== 'verified') {
            $out[] = [
                'severity' => 'warning',
                'title' => __('projects.problem_backup_unverified_title'),
                'detail' => __('projects.problem_backup_unverified_detail'),
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
            // 0.6.1 — a connector that DEFINITIVELY cannot stream says so in
            // the Review's own words; "Starting" is reserved for operations
            // that exist. An unresolvable source stays neutral ("Not
            // running") — there is no connector to accuse.
            'label' => $this->declaresChangeCapture() === false && $this->checkpoint() === null
                ? __('projects.sync_unsupported')
                : match ($state) {
                    JourneyState::COMPLETE => __('projects.sync_up_to_date'),
                    JourneyState::IN_PROGRESS => __('projects.sync_starting'),
                    JourneyState::NEEDS_ATTENTION => __('projects.sync_behind'),
                    JourneyState::BLOCKED => __('projects.sync_stopped'),
                    default => __('projects.sync_not_running'),
                },
            'detail' => $this->syncDetail(),
            'lagSeconds' => $this->syncLagSeconds(),
        ];
    }

    private function syncDetail(): string
    {
        if ($this->declaresChangeCapture() === false && $this->checkpoint() === null) {
            return __('projects.stage_sync_unsupported');
        }

        $checkpoint = $this->checkpoint();
        if (! $checkpoint) {
            return __('projects.stage_sync_none');
        }

        $lag = $this->syncLagSeconds();
        if ($lag === null) {
            return __('projects.stage_sync_no_events');
        }

        return __('projects.stage_sync_last', ['when' => $this->durationAgo($lag)]);
    }

    private function validateDetail(): string
    {
        $checks = $this->readinessChecks();
        if ($checks->isEmpty()) {
            return __('projects.stage_validate_none');
        }

        $failed = $checks->where('status', 'failed')->count();
        $warned = $checks->where('status', 'warning')->count();

        if ($failed > 0) {
            return (string) trans_choice('projects.stage_validate_failed', $failed, ['count' => $failed]);
        }
        if ($warned > 0) {
            return (string) trans_choice('projects.stage_validate_warning', $warned, ['count' => $warned]);
        }

        return __('projects.stage_validate_pass', ['count' => $checks->count()]);
    }

    private function cutoverDetail(): string
    {
        $plan = $this->cutoverPlan();
        if (! $plan) {
            return __('projects.stage_cutover_none');
        }

        $blocking = $this->blockingChecks()->count();
        if ($blocking > 0) {
            return (string) trans_choice('projects.stage_cutover_blocking', $blocking, ['count' => $blocking]);
        }

        return __('projects.stage_cutover_status', ['state' => JourneyState::fromRaw($plan->status)->label()]);
    }

    /**
     * 0.6.1 — can this project's migration source capture changes at all?
     *
     * Resolves the SAME fact the wizard Review's `selectedConnectorSupportsLiveSync()`
     * resolves — the connector's declared capabilities include `change_capture`
     * — so the Review verdict and every pulse surface (journey tab, overview
     * fact, cutover gate) can never disagree about the same connector. The
     * run's own plan source decides (the source actually migrated from), with
     * the project's first source as fallback.
     */
    private function liveSyncSupported(): bool
    {
        return $this->declaresChangeCapture() ?? false;
    }

    /**
     * Tri-state support fact: TRUE when the connector declares change
     * capture, FALSE when it definitively does not (the "Not supported by
     * this connector" wording case), NULL when it cannot be resolved at all
     * (no source yet, unknown/legacy adapter, disabled connector). NULL must
     * degrade to "not supported" for STATE purposes — an unresolvable
     * connector can never stream — but stays silent about WHY.
     */
    private function declaresChangeCapture(): ?bool
    {
        try {
            $source = $this->latestPlan()?->analysis?->source
                ?? $this->sources()->first();
            if ($source === null) {
                return null;
            }

            $connector = ConnectorRegistry::instance()->connectorForSource($source);

            return in_array(
                ConnectorCapability::CHANGE_CAPTURE,
                $connector->definition()->capabilities ?? [],
                true,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 0.6.1 — whether the latest run could have written anything. The `mode`
     * column is authoritative (`start()` keeps it and the `dry_run` flag in
     * step, but only `mode` is part of the guaranteed vocabulary), and a run
     * counts only when it is a WRITING mode — a dry run transfers nothing.
     */
    private function latestRunTransferred(): bool
    {
        $run = $this->latestRun();

        return $run !== null
            && ! $run->dry_run
            && in_array((string) $run->mode, ['rehearsal', 'real'], true);
    }

    public function syncLagSeconds(): ?int
    {        $checkpoint = $this->checkpoint();
        if (! $checkpoint || ! $checkpoint->last_event_at) {
            return null;
        }

        // `checkpoint()` reads the row via the query builder, so
        // `last_event_at` arrives as a raw STRING — calling ->diffInSeconds()
        // on it fatals the Home/Overview screens for any project whose
        // checkpoint carries a last-event time (found live on the operator
        // VPS during rc.2 acceptance). Normalize before measuring.
        $last = $checkpoint->last_event_at;

        return max(0, (int) Carbon::parse($last)->diffInSeconds(now(), false));
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
            return ['state' => JourneyState::NOT_STARTED, 'label' => __('projects.backup_never'), 'detail' => __('projects.backup_none_detail')];
        }

        $state = match (true) {
            in_array($backup->status, ['failed', 'error'], true) => JourneyState::BLOCKED,
            $backup->status === 'running' => JourneyState::IN_PROGRESS,
            $backup->restore_test_status !== 'verified' => JourneyState::NEEDS_ATTENTION,
            default => JourneyState::COMPLETE,
        };

        return [
            'state' => $state,
            // The timestamp humanizes in the user's locale; a raw stored
            // status never renders (§A5 — the dictionary owns state words).
            'label' => $backup->finished_at?->diffForHumans() ?? ProductStatus::label((string) $backup->status),
            'detail' => $backup->restore_test_status === 'verified'
                ? __('projects.backup_verified_detail')
                : __('projects.backup_unverified_detail'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Data access — small, project-scoped, tolerant of a missing table
    // ─────────────────────────────────────────────────────────────────

    private function sources(): Collection
    {
        return $this->sourcesCache ??= $this->safe(
            fn () => MigrationSource::query()->where('project_id', $this->project->id)->get(),
            new Collection,
        );
    }

    private function latestAnalysis(): ?MigrationAnalysis
    {
        if (! $this->analysisResolved) {
            $this->analysisCache = $this->safe(fn () => MigrationAnalysis::query()
                ->where('project_id', $this->project->id)
                ->latest('id')
                ->first());
            $this->analysisResolved = true;
        }

        return $this->analysisCache;
    }

    private function latestPlan(): ?MigrationPlan
    {
        if (! $this->planResolved) {
            $this->planCache = $this->safe(fn () => MigrationPlan::query()
                ->where('project_id', $this->project->id)
                ->latest('id')
                ->first());
            $this->planResolved = true;
        }

        return $this->planCache;
    }

    private function runs(): Collection
    {
        return $this->runsCache ??= $this->safe(
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
        if ($this->checkpointResolved) {
            return $this->checkpointCache;
        }

        $this->checkpointResolved = true;

        return $this->checkpointCache = $this->safe(function () {
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
        return $this->checksCache ??= $this->safe(
            fn () => ReadinessCheck::query()->where('project_id', $this->project->id)->get(),
            new Collection,
        );
    }

    /**
     * How many readiness checks the project knows about (0 = validation never
     * ran). The Overview readiness fact uses this to say "not run yet" instead
     * of a misleading zero (§D3).
     */
    public function readinessCheckCount(): int
    {
        return $this->readinessChecks()->count();
    }

    /**
     * §D3 — the readiness fact's honest figures, from readiness checks ONLY.
     * General project warnings (health result, backups) must never masquerade
     * as validation results.
     *
     * @return array{total: int, failed: int, warned: int}
     */
    public function readinessSummary(): array
    {
        $checks = $this->readinessChecks();

        return [
            'total' => $checks->count(),
            'failed' => $checks->where('status', 'failed')->count(),
            'warned' => $checks->where('status', 'warning')->count(),
        ];
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
        if (! $this->cutoverPlanResolved) {
            $this->cutoverPlanCache = $this->safe(fn () => CutoverPlan::query()
                ->where('project_id', $this->project->id)
                ->latest('id')
                ->first());
            $this->cutoverPlanResolved = true;
        }

        return $this->cutoverPlanCache;
    }

    private function latestBackup(): ?BackupRecord
    {
        if (! $this->backupResolved) {
            $this->backupCache = $this->safe(fn () => BackupRecord::query()
                ->where('db_name', $this->project->db_name)
                ->latest('finished_at')
                ->first());
            $this->backupResolved = true;
        }

        return $this->backupCache;
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

    /**
     * 0.6.0 Phase D (§D10) — a localized "2 hours ago" / "منذ ساعتين" for
     * translated sentences. `humanDuration()` above predates localization and
     * still feeds Phase E migration surfaces; new product copy uses this.
     */
    public function durationAgo(int $seconds): string
    {
        return now()->subSeconds(max(0, $seconds))->diffForHumans();
    }
}
