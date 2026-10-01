<?php

namespace App\Console\Commands;

use App\Models\MigrationRun;
use App\Services\ControlPlane\Migration\Cdc\CdcCheckpointManager;
use App\Services\ControlPlane\Migration\Cdc\CdcRunContext;
use Illuminate\Console\Command;

/**
 * Phase 35.6 — drive REAL log-based CDC capture for a migration run.
 *
 * Provider-neutral: the connector is resolved through the registry and
 * MUST provide a CdcCaptureProvider (real log capture). Sources without a
 * real log mechanism fail honestly — this command NEVER falls back to
 * watermark incremental export (that path is NOT log-based CDC).
 *
 * Restart/resume: kill and re-run at any point — capture resumes from the
 * last SIGNED checkpoint; replay is idempotent (at-least-once delivery,
 * documented).
 */
class CdcCaptureCommand extends Command
{
    protected $signature = 'migration:cdc-capture
        {run : Migration run id or run UUID}
        {--cycles=1 : Maximum capture cycles}
        {--idle-exit= : Stop after N consecutive idle seconds (caught-up exit)}
        {--stop-file= : Exit when this file exists (operator stop)}
        {--target-key= : Checkpoint target key (default "")}
        {--status : Show the current checkpoint status and exit}';

    protected $description = 'Run log-based CDC capture/apply for a migration run (Phase 35.6)';

    public function handle(CdcCheckpointManager $checkpoints): int
    {
        $run = ctype_digit((string) $this->argument('run'))
            ? MigrationRun::find((int) $this->argument('run'))
            : MigrationRun::where('run_id', (string) $this->argument('run'))->first();
        if ($run === null) {
            $this->error("migration run '{$this->argument('run')}' not found");

            return self::FAILURE;
        }
        $targetKey = (string) $this->option('target-key');

        if ((bool) $this->option('status')) {
            return $this->showStatus($run->id, $targetKey, $checkpoints);
        }

        try {
            $context = CdcRunContext::forRun($run, ['target_key' => $targetKey]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $stopFile = $this->option('stop-file');
        $summary = $context->worker->run(
            max(1, (int) $this->option('cycles')),
            $stopFile !== null ? fn (): bool => file_exists($stopFile) : null,
            (int) ($this->option('idle-exit') ?: 0),
        );

        $this->line(json_encode([
            'run' => $run->run_id,
            'capture_kind' => $context->capture->checkpointKind(),
            'source_type' => $context->source->type,
        ] + $summary));

        return self::SUCCESS;
    }

    protected function showStatus(int $runId, string $targetKey, CdcCheckpointManager $checkpoints): int
    {
        try {
            $checkpoint = $checkpoints->load($runId, $targetKey);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($checkpoint === null) {
            $this->line(json_encode(['checkpoint' => null]));

            return self::SUCCESS;
        }
        $this->line(json_encode([
            'checkpoint' => [
                'kind' => $checkpoint->kind,
                'position' => $checkpoint->position,
                'applied_events' => $checkpoint->applied_events,
                'lag_events' => $checkpoint->lag_events,
                'stream_status' => $checkpoint->stream_status,
                'last_event_at' => $checkpoint->last_event_at?->toIso8601String(),
                'stream_telemetry' => $checkpoint->stream_telemetry,
                'updated_at' => $checkpoint->updated_at?->toIso8601String(),
            ],
        ], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
