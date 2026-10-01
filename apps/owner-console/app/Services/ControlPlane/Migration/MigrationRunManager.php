<?php

namespace App\Services\ControlPlane\Migration;

use App\Models\MigrationPlan;
use App\Models\MigrationRun;
use App\Models\MigrationRunItem;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\Migration\Contracts\SourceAdapter;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use App\Services\ControlPlane\Migration\Contracts\TargetAdapter;
use App\Services\ControlPlane\Migration\SourceAdapters\SqliteSourceAdapter;
use App\Services\ControlPlane\Migration\TargetAdapters\PostgresTargetAdapter;
use App\Services\ControlPlane\Migration\TargetAdapters\SqliteTargetAdapter;
use App\Services\ControlPlane\SecretService;
use Illuminate\Support\Str;

/**
 * 24A.9 / 24A.11 / 24J.1 — migration run execution with hard guardrails.
 *
 * SOURCE/TARGET GUARDRAILS (fail closed):
 *  - target must not be production (mode rehearsal/dry_run only, ever);
 *  - destructive reset requires target explicitly marked disposable;
 *  - source and target must not be the same database;
 *  - runs display SOURCE and TARGET clearly and audit both (redacted).
 */
class MigrationRunManager
{
    public const MODES = ['dry_run', 'rehearsal', 'real'];

    /** Create a run (guard rails first) with per-item rows in stage order. */
    public function start(MigrationPlan $plan, array $params): MigrationRun
    {
        $mode = $params['mode'] ?? 'dry_run';
        abort_unless(in_array($mode, self::MODES, true), 422, 'Unknown run mode');

        $target = $params['target'] ?? [];
        $targetDisposable = (bool) ($params['target_disposable'] ?? false);
        $targetEnvType = $params['target_environment_type'] ?? 'development';

        // GUARD 1: never production.
        abort_if($targetEnvType === 'production', 422, 'Migration runs against production are not supported.');

        // GUARD 2: destructive ops need disposable target.
        abort_if(($params['reset'] ?? false) && ! $targetDisposable, 422, 'Reset requires an explicitly disposable target.');

        // GUARD 3: source == target confusion (plan source AND every project source).
        $targetKey = $this->connectionKey($target);
        $sourceKey = $this->connectionKey($plan->analysis->source->connection ?? []);
        abort_if($sourceKey !== '' && $sourceKey === $targetKey && $mode !== 'dry_run', 422, 'Source and target are the same database.');
        foreach (\App\Models\MigrationSource::where('project_id', $plan->project_id)->get() as $projectSource) {
            $otherKey = $this->connectionKey($projectSource->connection ?? []);
            abort_if($otherKey !== '' && $otherKey === $targetKey && $mode !== 'dry_run', 422, "Target matches read-only SOURCE connection '{$projectSource->display_name}' — migration targets must never be a source.");
        }
        // A target that is ALSO the analysis source DSN (reversal attack) is caught above.

        $run = MigrationRun::create([
            'project_id' => $plan->project_id,
            'migration_plan_id' => $plan->id,
            'run_id' => (string) Str::uuid(),
            'dry_run' => $mode === 'dry_run',
            'mode' => $mode,
            'target_connection' => $this->redactedTarget($target),
            'target_disposable' => $targetDisposable,
            'status' => 'pending',
            'created_by' => auth()->id(),
            'progress' => ['total' => $plan->items()->whereIn('status', ['READY', 'MAPPED'])->count(), 'done' => 0, 'failed' => 0, 'skipped' => 0, 'reset' => (bool) ($params['reset'] ?? false)],
        ]);

        foreach ($plan->items()->orderBy('stage')->orderBy('id')->get() as $item) {
            if ($item->status === 'DISCOVERED') {
                continue;
            }
            MigrationRunItem::create([
                'migration_run_id' => $run->id,
                'migration_plan_item_id' => $item->id,
                'stage' => $item->stage,
                'status' => 'pending',
            ]);
        }

        AdminAudit::record('MIGRATION_RUN_STARTED', $plan->project, 'migration_run', $run->id, [
            'run_id' => $run->run_id, 'mode' => $mode,
            'source' => $this->redactedTarget($plan->analysis->source->connection ?? []),
            'target' => $run->target_connection,
        ]);

        return $run;
    }

    /**
     * Execute (or resume) a run. Stages run sequentially; items complete/
     * fail individually — resume re-runs only pending/failed items, and a
     * cancel request is honored between items (cancel-safe).
     */
    public function execute(MigrationRun $run): MigrationRun
    {
        $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now()]);
        $plan = $run->plan;
        $source = $this->makeSource($plan->analysis->source);
        $target = $this->makeTarget($run, $plan);

        try {
            $source->connect();

            if ($run->mode !== 'dry_run') {
                $target = $this->makeTarget($run, $plan);
                $target->connect();
                $this->ensureSchema($plan, $target, $run);
            } else {
                $target = $this->makeTarget($run, $plan);
            }

            $stages = $run->items()->with('planItem')->get()->groupBy('stage')->sortKeys();
            foreach ($stages as $stage => $runItems) {
                foreach ($runItems as $runItem) {
                    $run->refresh();
                    if ($run->status === 'cancel_requested') {
                        $run->update(['status' => 'cancelled', 'finished_at' => now()]);
                        AdminAudit::record('MIGRATION_RUN_CANCELLED', $plan->project, 'migration_run', $run->id, ['run_id' => $run->run_id]);

                        return $run;
                    }
                    if (in_array($runItem->status, ['completed', 'skipped'], true)) {
                        continue; // resume: completed items are never re-run
                    }
                    $this->runItem($run, $runItem, $source, $target);
                }
            }

            $run->refresh();
            $failed = $run->items()->where('status', 'failed')->count();
            $run->update([
                'status' => $failed > 0 ? 'failed' : 'completed',
                'finished_at' => now(),
                'progress' => [
                    'total' => $run->items()->count(),
                    'done' => $run->items()->where('status', 'completed')->count(),
                    'failed' => $failed,
                    'skipped' => $run->items()->where('status', 'skipped')->count(),
                ],
            ]);
            AdminAudit::record($failed > 0 ? 'MIGRATION_RUN_FAILED' : 'MIGRATION_RUN_COMPLETED', $plan->project, 'migration_run', $run->id, [
                'run_id' => $run->run_id, 'mode' => $run->mode, 'failed_items' => $failed,
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => 'failed', 'finished_at' => now(),
                'failure' => ['error' => $e->getMessage()],
            ]);
            AdminAudit::record('MIGRATION_RUN_FAILED', $plan->project, 'migration_run', $run->id, [
                'run_id' => $run->run_id, 'error' => Str::limit($e->getMessage(), 300),
            ]);
        } finally {
            $source->close();
            $target?->close();
        }

        return $run;
    }

    /** 24A.11 — rehearse a clean migration on an explicitly disposable target. */
    public function rehearseClean(MigrationPlan $plan, array $target, string $mode = 'rehearsal'): array
    {
        abort_if(empty($target['disposable']), 422, 'Clean rehearsal requires an explicitly disposable target.');
        $result = ['runs' => [], 'deterministic' => true, 'comparison' => []];

        for ($i = 1; $i <= 2; $i++) {
            $run = $this->start($plan, [
                'mode' => $mode,
                'target' => $target,
                'target_disposable' => true,
                'reset' => true,
                'target_environment_type' => $target['environment_type'] ?? 'development',
            ]);
            $this->execute($run);
            $this->validate($run);
            $result['runs'][] = $run->run_id;
            $result['comparison'][] = [
                'run' => $run->run_id,
                'status' => $run->status,
                'progress' => $run->progress,
                // 28.1G — determinism must compare written DATA, not only
                // run bookkeeping: a duplicated second pass previously
                // reported "deterministic" while the target diverged.
                'rows_by_item' => $run->items()->get()
                    ->mapWithKeys(fn ($i) => [(string) $i->migration_plan_item_id => (int) $i->rows_written])
                    ->sortKeys()->all(),
            ];
        }

        // Determinism: both clean runs must produce identical completed counts,
        // statuses AND identical per-item written row counts.
        $first = $result['comparison'][0];
        $second = $result['comparison'][1];
        $result['deterministic'] = $first['status'] === $second['status']
            && ($first['progress']['done'] ?? 0) === ($second['progress']['done'] ?? 0)
            && ($first['progress']['failed'] ?? 0) === ($second['progress']['failed'] ?? 0)
            && $first['rows_by_item'] === $second['rows_by_item'];

        AdminAudit::record('CLEAN_REHEARSAL_RUN', $plan->project, 'migration_plan', $plan->id, [
            'deterministic' => $result['deterministic'],
        ]);

        return $result;
    }

    /** Run the validator suite for a completed run. */
    public function validate(MigrationRun $run): array
    {
        $plan = $run->plan;
        $source = $this->makeSource($plan->analysis->source);
        $target = $this->makeTarget($run, $plan, false); // never recreate while validating
        $source->connect();
        $target->connect();
        try {
            $suite = new ValidatorSuite($source, $target);
            $names = [];
            $plan->items()->get()->each(function ($item) use (&$names) {
                foreach (array_filter(explode(',', (string) $item->validation)) as $v) {
                    $names[$v] = true;
                }
            });
            $results = $suite->run(array_keys($names), $plan->items()->get()->all());
            $anyFail = collect($results)->contains(fn ($r) => $r['status'] === 'fail');
            $run->items()->get()->each(function ($runItem) use ($results) {
                $relevant = array_intersect_key($results, array_flip(array_filter(explode(',', (string) $runItem->planItem->validation))));
                if ($relevant !== []) {
                    $runItem->update(['validation' => $relevant]);
                }
            });
            if ($run->status === 'completed' || $run->status === 'failed') {
                if (! $anyFail) {
                    AdminAudit::record('MIGRATION_VALIDATED', $plan->project, 'migration_run', $run->id, ['run_id' => $run->run_id]);
                }
            }

            return $results;
        } finally {
            $source->close();
            $target->close();
        }
    }

    public function cancel(MigrationRun $run): void
    {
        abort_unless(in_array($run->status, ['pending', 'running'], true), 422, 'Run is not cancellable.');
        $run->update(['status' => 'cancel_requested']);
    }

    // ── Internals ───────────────────────────────────────────────────────

    protected function runItem(MigrationRun $run, MigrationRunItem $runItem, SourceAdapter $source, TargetAdapter $target): void
    {
        $item = $runItem->planItem;
        $runItem->update(['status' => 'running', 'attempts' => $runItem->attempts + 1, 'started_at' => now()]);

        try {
            $written = 0;
            if ($run->mode !== 'dry_run') {
                // 28.1G finding: the run-level reset flag (set for clean
                // rehearsals) must reach every table item — without it the
                // second pass duplicates child-table rows on a non-upsert
                // insert path and the target silently diverges.
                $reset = (bool) ($run->progress['reset'] ?? false);
                switch ($item->source_kind) {
                    case 'auth_users':
                        $written = $this->migrateAuthUsers($item, $source, $target, $reset);
                        break;
                    case 'table':
                        $written = $this->migrateTable($item, $source, $target, $reset);
                        break;
                    default:
                        // Non-data items (policies, functions, edge, cron, views)
                        // are planning artifacts — recorded, not executed.
                        $written = 0;
                }
            } else {
                // Dry run: count what WOULD be written.
                $written = $item->source_kind === 'table'
                    ? $source->countRows($item->source_schema ?? 'public', $item->source_name)
                    : 0;
            }

            $runItem->update([
                'status' => 'completed',
                'rows_written' => $written,
                'finished_at' => now(),
            ]);
            if ($item->status !== 'VALIDATED') {
                $item->update(['status' => 'MIGRATED']);
            }
        } catch (\Throwable $e) {
            $runItem->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500), 'finished_at' => now()]);
            $item->update(['status' => 'BLOCKED']);
        }
    }

    protected function migrateTable($item, SourceAdapter $source, TargetAdapter $target, bool $reset = false): int
    {
        if ($reset || ($item->meta['reset'] ?? false)) {
            // Only reachable when the run guard passed (disposable target).
            $target->truncateTable($item->target_name);
        }
        $columns = $item->meta['columns'] ?? [];
        $transforms = array_filter(array_map('trim', explode('|', (string) $item->transform)));
        $config = $item->meta['transform_config'] ?? [];
        $written = 0;
        $batch = [];
        $pk = $item->meta['pk'] ?? [];
        $lastPk = null;

        $source->streamRows($item->source_schema ?? 'public', $item->source_name, $columns, function ($row) use (&$batch, &$written, $transforms, $config, $columns, $target, $item, $pk, &$lastPk) {
            $row = self::projectColumns($row, $columns);
            foreach ($transforms as $t) {
                $row = TransformPipeline::apply($t, $row, $config[$t] ?? []);
            }
            if ($pk !== [] && isset($row[$pk[0]])) {
                $lastPk = $row[$pk[0]];
            }
            $batch[] = $row;
            if (count($batch) >= 500) {
                $written += $target->insertBatch($item->target_name, $batch);
                $batch = [];
            }
        });
        $written += $target->insertBatch($item->target_name, $batch);

        // Sequence correctness: align target identity with copied max(PK).
        if (count($pk) === 1 && $lastPk !== null && is_numeric($lastPk)) {
            $target->setSequence($item->target_name, $pk[0], (int) $lastPk);
        }

        return $written;
    }

    protected function migrateAuthUsers($item, SourceAdapter $source, TargetAdapter $target, bool $reset = false): int
    {
        $authTable = $this->authTableName($item->plan);
        if (! $target->tableExists($authTable)) {
            return 0;
        }
        if ($reset) {
            $target->truncateTable($authTable);
        }
        $written = 0;
        $batch = [];
        $source->streamAuthUsers(function ($authRow) use (&$batch, &$written, $target, $authTable) {
            $row = TransformPipeline::apply('auth_identity_transform', [
                'id' => $authRow['id'],
                'email' => $authRow['email'],
                'password' => $authRow['encrypted_password'],
                'created_at' => $authRow['created_at'],
            ], [
                'columns' => ['id' => 'id', 'email' => 'email', 'password' => 'password', 'created_at' => 'created_at'],
                'hash_column' => 'password',
            ]);
            $batch[] = $row;
            if (count($batch) >= 500) {
                $written += $target->insertBatch($authTable, $batch);
                $batch = [];
            }
        });
        $written += $target->insertBatch($authTable, $batch);

        return $written;
    }

    /** Collision-aware auth identity table name (see PlanGenerator::authTargetTable). */
    protected function authTableName(MigrationPlan $plan): string
    {
        $usersTaken = $plan->items()
            ->where('source_kind', 'table')
            ->pluck('target_name')
            ->contains('users');

        return PlanGenerator::authTargetTable($usersTaken);
    }

    /** Build target schema from plan (tables first, then FKs). */
    protected function ensureSchema(MigrationPlan $plan, TargetAdapter $target, MigrationRun $run): void
    {
        if (! ($run->target_disposable && ($run->mode === 'rehearsal'))) {
            // Real/rehearsal on an existing schema: never auto-create or drop
            // unless disposable — schema management belongs to the operator.
            return;
        }
        $fks = [];

        // Enum types first (columns reference them).
        foreach ($plan->analysis->items()->where('kind', 'enum')->get() as $enum) {
            $target->ensureEnum($enum->name, $enum->attributes['values'] ?? []);
        }

        foreach ($plan->items()->where('source_kind', 'table')->orderBy('stage')->get() as $item) {
            $analysisItem = $plan->analysis->items()->where('kind', 'table')->where('name', $item->source_name)->first();
            if (! $analysisItem) {
                continue;
            }
            // ensureTable is CREATE TABLE IF NOT EXISTS — always call it: the
            // adapter also records the mapped column types there, which the
            // insert path needs for bytea binding even when the table already
            // existed (35.5 live finding).
            $attrs = $analysisItem->attributes;
            $columns = $attrs['columns'] ?? [];
            $pk = $attrs['primary_key'] ?? [];
            $target->ensureTable($item->target_name, $columns, $pk);
            foreach ($attrs['foreign_keys'] ?? [] as $fk) {
                $fks[] = [
                    'table' => $item->target_name,
                    'column' => $fk['column'],
                    'references_table' => $fk['references_table'],
                    'references_column' => $fk['references_column'],
                ];
            }
        }

        // Auth identity target table (auth template output).
        $authItem = $plan->items()->where('source_kind', 'auth_users')->first();
        if ($authItem && ! $target->tableExists($this->authTableName($plan))) {
            $target->ensureTable($this->authTableName($plan), [
                ['name' => 'id', 'type' => 'text', 'nullable' => false],
                ['name' => 'email', 'type' => 'text', 'nullable' => true],
                ['name' => 'password', 'type' => 'text', 'nullable' => true],
                ['name' => 'created_at', 'type' => 'text', 'nullable' => true],
            ], ['id']);
        }

        $target->applyForeignKeys($fks);
    }

    protected function makeSource($source): SourceAdapter
    {
        // Phase 27D.2 — provider-agnostic resolution through the connector
        // registry (the SQLite rehearsal fixture is resolved from the engine
        // map by MigrationCenterService::makeAdapter).
        return app(MigrationCenterService::class)->makeAdapter($source);
    }

    /** Phase 35.6 — public target resolution for the CDC capture worker. */
    public function targetFor(MigrationRun $run, MigrationPlan $plan): TargetAdapter
    {
        return $this->makeTarget($run, $plan, false);
    }

    protected function makeTarget(MigrationRun $run, MigrationPlan $plan, bool $recreate = true): TargetAdapter
    {
        $targetJson = json_decode((string) $run->target_connection, true) ?? [];
        $driver = $targetJson['driver'] ?? 'postgres';
        $config = [];
        if ($driver === 'sqlite') {
            $config = ['path' => $targetJson['path'] ?? ':memory:', 'recreate' => $recreate && ($targetJson['recreate'] ?? false)];

            return new SqliteTargetAdapter($config);
        }
        // PostgreSQL target: resolve password from vault ref (never stored).
        $refs = $targetJson['secret_refs'] ?? [];
        $password = '';
        if (! empty($refs['password'])) {
            $values = SecretService::valuesFor($plan->project, [$refs['password']]);
            $password = (string) ($values[$refs['password']] ?? '');
        }
        $config = [
            'host' => $targetJson['host'] ?? '127.0.0.1',
            'port' => (int) ($targetJson['port'] ?? 5432),
            'database' => $targetJson['database'] ?? 'postgres',
            'username' => $targetJson['username'] ?? 'postgres',
            'password' => $password,
        ];

        return new PostgresTargetAdapter($config);
    }

    protected function projectColumns(array $row, array $columns): array
    {
        if ($columns === []) {
            return $row;
        }
        $out = [];
        foreach ($columns as $c) {
            $out[$c] = $row[$c] ?? null;
        }

        return $out;
    }

    protected function connectionKey(array $conn): string
    {
        // Includes path so sqlite fixture sources/targets are compared too.
        return strtolower(json_encode([
            'host' => $conn['host'] ?? '',
            'port' => $conn['port'] ?? '',
            'database' => $conn['database'] ?? '',
            'path' => $conn['path'] ?? '',
        ]));
    }

    /** Redact credential VALUES from a connection array; secret REF names stay. */
    protected function redactedTarget(array $conn): string
    {
        $safe = $conn;
        unset($safe['password'], $safe['manifest']);
        foreach ($safe as $k => $v) {
            if (is_string($v) && preg_match('/(password|secret|token|key)/i', $k) && ! str_contains($k, 'secret_refs')) {
                $safe[$k] = '***';
            }
        }

        return json_encode($safe, JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
