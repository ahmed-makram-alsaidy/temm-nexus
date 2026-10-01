<?php

namespace App\Services\ControlPlane\Migration\Cdc;

use App\Models\MigrationRun;
use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Connectors\Contracts\CdcCaptureProvider;
use App\Services\ControlPlane\Migration\MigrationRunManager;

/**
 * Phase 35.6 — builds the full capture context for one migration run:
 * run → source → connector capture (must be a real CdcCaptureProvider) →
 * target applier → generic worker. Shared by the artisan command and the
 * Cutover Center's final-delta workflow so both behave IDENTICALLY.
 */
class CdcRunContext
{
    public function __construct(
        public readonly MigrationRun $run,
        public readonly MigrationSource $source,
        public readonly ChangeCaptureConnector $capture,
        public readonly CdcCaptureWorker $worker,
        public readonly string $targetKey,
    ) {
    }

    public static function forRun(MigrationRun $run, array $options = []): self
    {
        $plan = $run->plan()->with('analysis.source')->firstOrFail();
        $source = $plan->analysis->source ?? null;
        if ($source === null) {
            throw new \RuntimeException('the run plan has no migration source');
        }

        $connector = app(ConnectorRegistry::class)->connectorForSource($source);
        if (! $connector instanceof CdcCaptureProvider) {
            // Honest refusal — log-based CDC is NEVER faked with watermark
            // incremental export (35.6).
            throw new \RuntimeException("connector '{$source->effectiveConnectorKey()}' does not provide real log-based CDC capture");
        }

        $targetKey = (string) ($options['target_key'] ?? '');
        $projectSlug = (string) ($plan->project->slug ?? ('p'.$run->project_id));

        // Phase 36 — collections whose analysis carries a sanitized field
        // map (MongoDB) pass it to the capture so change-stream rows are
        // projected through the SAME field map the snapshot used; without
        // it, flattened/nested target columns go NULL on apply and the
        // stream pauses on the first event. Connectors without a field map
        // concept ignore the option.
        $tableFieldMaps = [];
        foreach ($plan->items()->where('source_kind', 'table')->get() as $item) {
            $analysisItem = $plan->analysis->items()
                ->where('kind', 'table')->where('name', $item->source_name)
                ->first();
            $attrs = $analysisItem?->attributes ?? [];
            if (isset($attrs['sanitized_field_map']) && is_array($attrs['sanitized_field_map'])) {
                $tableFieldMaps[(string) $item->source_name] = [
                    'field_map' => $attrs['sanitized_field_map'],
                    'strategy' => (string) ($attrs['migration_strategy'] ?? 'document'),
                    'table_def' => $attrs,
                ];
            }
        }

        $captureOptions = array_merge([
            'slot' => self::scopedName('temm_slot', $projectSlug, (string) $run->run_id),
            'publication' => self::scopedName('temm_pub', $projectSlug, (string) $run->run_id),
            'app_name' => (string) config('cdc.capture.replication_app_name', 'temm-nexus-cdc'),
            'server_id' => (int) config('cdc.capture.mysql_server_id_base', 420240000) + ($run->id % 1000000),
            'tables' => $plan->items()->where('source_kind', 'table')->get()
                ->map(fn ($item) => trim(($item->source_schema ?? '').'.'.($item->source_name ?? ''), '.'))
                ->filter()->values()->all(),
            'database' => (string) ($source->connection['database'] ?? ''),
            'run_id' => (string) $run->run_id,
        ], $options);
        if ($tableFieldMaps !== []) {
            $captureOptions['table_field_maps'] = $tableFieldMaps;
        }

        $capture = $connector->cdcCapture($source, $captureOptions);

        $target = app(MigrationRunManager::class)->targetFor($run, $plan);
        $target->connect();

        $worker = new CdcCaptureWorker(
            $capture,
            new CdcApplier($target, self::tableKeys($plan)),
            new CdcCheckpointManager,
            $run->id,
            (string) $source->type,
            $targetKey,
        );

        return new self($run, $source, $capture, $worker, $targetKey);
    }

    /** Project+run scoped, lowercase, 63-char safe replication identifier. */
    public static function scopedName(string $prefix, string $projectSlug, string $runUuid): string
    {
        $name = $prefix.'_'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($projectSlug)).'_'.substr(md5($runUuid), 0, 12);

        return substr($name, 0, 63);
    }

    /** Source table name → primary key columns, from the plan analysis. */
    protected static function tableKeys($plan): array
    {
        $keys = [];
        foreach ($plan->analysis->items()->where('kind', 'table')->get() as $item) {
            $pk = $item->attributes['primary_key'] ?? [];
            if ($pk !== []) {
                $keys[(string) $item->name] = array_values($pk);
            }
        }

        return $keys;
    }
}
