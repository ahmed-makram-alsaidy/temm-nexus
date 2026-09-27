<?php

namespace App\Services\ControlPlane\Migration;

/**
 * 24J.2 + 24J.10 — machine-readable mapping manifest + the reusable
 * `supabase-standard-migration` template derived from prior conversion
 * experience WITHOUT any project-specific domain semantics.
 *
 * A mapping manifest entry: {source_table, target_table, key_strategy,
 * column_map, transform, dependency, validation, status}. The engine never
 * encodes project names.
 */
class MigrationTemplates
{
    /** The generic standard template (id → definition). */
    public static function standard(): array
    {
        return [
            'id' => 'supabase-standard-migration',
            'description' => 'Reusable Supabase → platform migration template: auth, tables by FK order, RLS review, storage, RPC/edge inventory, realtime, cron.',
            'phases' => [
                ['id' => 'auth', 'strategy' => 'auth_identity_transform', 'notes' => 'UUID + hash preservation; profile linkage; hash strategy source-analyzed'],
                ['id' => 'enums', 'strategy' => 'schema_build'],
                ['id' => 'tables', 'strategy' => 'fk_topological_order', 'validation' => 'row_counts,fk_orphans,pk_preservation,checksums,sequence_correctness'],
                ['id' => 'views', 'strategy' => 'rebuild_review'],
                ['id' => 'rls', 'strategy' => 'rls_review', 'notes' => 'candidate Laravel policy mapping + ALLOW/DENY test matrix'],
                ['id' => 'rpc', 'strategy' => 'rpc_classify', 'classification' => ['keep_postgresql', 'laravel_service', 'server_function', 'queue_job', 'needs_review']],
                ['id' => 'edge_functions', 'strategy' => 'edge_checklist', 'classification' => ['internal_logic', 'provider_integration', 'webhook', 'scheduled_job', 'server_function', 'obsolete']],
                ['id' => 'storage', 'strategy' => 'storage_copy', 'notes' => 'bucket/object inventory, checksum, path preservation, URL rewrite'],
                ['id' => 'realtime', 'strategy' => 'reverb_mapping'],
                ['id' => 'cron', 'strategy' => 'scheduler_convert'],
            ],
            'defaults' => ['batch_size' => 500, 'url_scan' => true, 'retry_resume' => true],
        ];
    }

    /** Validate an operator-supplied mapping manifest (24J.2). */
    public static function validateManifest(array $manifest): array
    {
        $errors = [];
        $required = ['source_table', 'target_table', 'key_strategy'];
        foreach (($manifest['mappings'] ?? []) as $i => $mapping) {
            foreach ($required as $field) {
                if (empty($mapping[$field])) {
                    $errors[] = "mapping[$i].$field is required";
                }
            }
            if (isset($mapping['transform']) && ! in_array($mapping['transform'], array_keys(TransformPipeline::catalog()), true)) {
                $errors[] = "mapping[$i].transform '{$mapping['transform']}' is not a known transform";
            }
        }

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /** Apply a template/manifest onto a plan: pre-map + transform configs. */
    public static function applyManifest(\App\Models\MigrationPlan $plan, array $manifest): int
    {
        $applied = 0;
        $items = $plan->items()->where('source_kind', 'table')->get();
        foreach ($items as $item) {
            foreach (($manifest['mappings'] ?? []) as $mapping) {
                if (($mapping['source_table'] ?? '') === $item->source_name) {
                    $item->update([
                        'target_name' => $mapping['target_table'],
                        'transform' => $mapping['transform'] ?? $item->transform,
                        'meta' => array_merge($item->meta ?? [], [
                            'transform_config' => $mapping['transform_config'] ?? [],
                            'key_strategy' => $mapping['key_strategy'],
                        ]),
                        'status' => 'READY',
                    ]);
                    $applied++;
                }
            }
        }

        return $applied;
    }
}
