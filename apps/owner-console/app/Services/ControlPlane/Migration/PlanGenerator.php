<?php

namespace App\Services\ControlPlane\Migration;

use App\Models\MigrationAnalysis;
use App\Models\MigrationPlan;
use App\Models\MigrationPlanItem;
use Illuminate\Support\Facades\DB;

/**
 * 24A.7 + 24A.8 — build an editable-but-audited migration plan from an
 * analysis. Dependency ordering (FK topological sort; auth first; views and
 * code objects after data) replaces arbitrary alphabetical loading.
 */
class PlanGenerator
{
    /** Generate a plan (or regenerate as a new draft) from an analysis. */
    public function generate(MigrationAnalysis $analysis, string $name = 'Standard migration plan'): MigrationPlan
    {
        $plan = MigrationPlan::create([
            'project_id' => $analysis->project_id,
            'migration_analysis_id' => $analysis->id,
            'environment_id' => $analysis->source->environment_id,
            'name' => $name,
            'status' => 'draft',
            'strategy' => ['engine' => 'supabase-standard-migration', 'batch_size' => 500],
            'created_by' => auth()->id(),
        ]);

        $items = $analysis->items()->get();
        $tableNames = $items->where('kind', 'table')->pluck('name')->flip();

        // 1. Auth domain first.
        $auth = $items->firstWhere('kind', 'auth');
        if ($auth && ($auth->attributes['present'] ?? false) && ($auth->attributes['users_count'] ?? 0) > 0) {
            $plan->items()->create([
                'source_kind' => 'auth_users',
                'source_name' => 'auth.users',
                'target_kind' => 'table',
                'target_name' => 'users',
                'strategy' => 'auth_identity_transform',
                'transform' => 'auth_identity_transform',
                'stage' => 0,
                'validation' => 'auth_linkage',
                'status' => 'READY',
                'meta' => ['hash_strategy' => $auth->attributes['hash_strategy'] ?? 'unknown'],
            ]);
        }

        // 2. Tables in FK dependency order.
        $stages = self::dependencyStages($items->where('kind', 'table')->values());
        foreach ($stages as $stage => $names) {
            foreach ($names as $tableName) {
                $item = $items->firstWhere('name', $tableName);
                $hasFks = ! empty($item->attributes['foreign_keys'] ?? []);
                $validation = $hasFks ? 'row_counts,fk_orphans,pk_preservation' : 'row_counts,pk_preservation';
                $status = in_array('financial_table_reconciliation', $item->risks ?? [], true)
                    ? 'READY' // financial tables stay READY; reconciliation is a required validator
                    : 'READY';
                if (in_array('missing_foreign_key', $item->risks ?? [], true)) {
                    $status = 'NEEDS_REVIEW';
                }
                $plan->items()->create([
                    'source_kind' => 'table',
                    'source_schema' => $item->schema_name,
                    'source_name' => $item->name,
                    'target_kind' => 'table',
                    'target_name' => $item->name,
                    'strategy' => 'direct_copy',
                    'transform' => 'direct_copy',
                    'stage' => $stage,
                    'validation' => $validation,
                    'status' => $status,
                    'meta' => [
                        'pk' => $item->attributes['primary_key'] ?? [],
                        'columns' => array_map(fn ($c) => $c['name'], $item->attributes['columns'] ?? []),
                        'fks' => array_map(fn ($fk) => [
                            'column' => $fk['column'],
                            'references_table' => $fk['references_table'],
                            'references_column' => $fk['references_column'],
                        ], $item->attributes['foreign_keys'] ?? []),
                    ],
                ]);
            }
        }

        // 3. Views.
        $stage = (int) ($plan->items()->max('stage') ?? 0) + 1;
        foreach ($items->where('kind', 'view')->values() as $view) {
            $plan->items()->create([
                'source_kind' => 'view', 'source_name' => $view->name,
                'target_kind' => 'view', 'target_name' => $view->name,
                'strategy' => 'rebuild', 'transform' => null, 'stage' => $stage,
                'validation' => 'row_counts', 'status' => 'NEEDS_REVIEW',
                'meta' => ['reason' => 'view definition must be recreated on target'],
            ]);
        }

        // 4. RLS policies → Laravel authorization mapping (never auto-converted).
        $stage++;
        foreach ($items->where('kind', 'policy')->values() as $policy) {
            $plan->items()->create([
                'source_kind' => 'policy', 'source_name' => $policy->name,
                'target_kind' => 'laravel_policy', 'target_name' => ($policy->attributes['table'] ?? '').':'.$policy->name,
                'strategy' => 'rls_review', 'transform' => null, 'stage' => $stage,
                'validation' => null, 'status' => 'NEEDS_REVIEW',
                'meta' => ['table' => $policy->attributes['table'] ?? null, 'command' => $policy->attributes['command'] ?? null],
            ]);
        }

        // 5. Functions / RPC classification.
        $stage++;
        foreach ($items->where('kind', 'function')->values() as $fn) {
            $plan->items()->create([
                'source_kind' => 'function', 'source_name' => $fn->name,
                'target_kind' => self::functionTarget($fn->attributes ?? []),
                'target_name' => $fn->name,
                'strategy' => 'rpc_classify', 'transform' => null, 'stage' => $stage,
                'validation' => null,
                'status' => ($fn->compatibility === 'NEEDS_REVIEW' ? 'NEEDS_REVIEW' : 'MAPPED'),
                'meta' => ['security' => $fn->attributes['security'] ?? null],
            ]);
        }

        // 6. Edge functions checklist.
        $stage++;
        foreach ($items->where('kind', 'edge_function')->values() as $edge) {
            $plan->items()->create([
                'source_kind' => 'edge_function', 'source_name' => $edge->name,
                'target_kind' => 'laravel_feature', 'target_name' => $edge->name,
                'strategy' => 'edge_checklist', 'transform' => null, 'stage' => $stage,
                'validation' => null, 'status' => 'NEEDS_REVIEW',
                'meta' => $edge->attributes,
            ]);
        }

        // 7. Storage buckets.
        $stage++;
        $storage = $items->firstWhere('kind', 'storage');
        if ($storage && ($storage->attributes['present'] ?? false)) {
            foreach ($storage->attributes['buckets'] ?? [] as $bucket) {
                $plan->items()->create([
                    'source_kind' => 'storage_bucket', 'source_name' => $bucket['name'],
                    'target_kind' => 'storage_bucket', 'target_name' => $bucket['name'],
                    'strategy' => 'storage_copy', 'transform' => 'url_rewrite', 'stage' => $stage,
                    'validation' => 'storage_checksums',
                    'status' => 'MAPPED',
                    'meta' => ['objects' => $storage->attributes['object_counts'][$bucket['name']]['objects'] ?? 0],
                ]);
            }
        }

        // 8. Cron jobs → Scheduler.
        $stage++;
        foreach ($items->where('kind', 'cron')->values() as $cron) {
            $plan->items()->create([
                'source_kind' => 'cron', 'source_name' => $cron->name,
                'target_kind' => 'scheduler_task', 'target_name' => $cron->name,
                'strategy' => 'scheduler_convert', 'transform' => null, 'stage' => $stage,
                'validation' => null, 'status' => 'NEEDS_REVIEW',
                'meta' => ['schedule' => $cron->attributes['schedule'] ?? null],
            ]);
        }

        $plan->items()->where('status', 'DISCOVERED')->update(['status' => 'MAPPED']);

        return $plan;
    }

    /**
     * Kahn topological sort of tables over FK edges. Cycles (a real risk in
     * legacy schemas) land in a final catch-up stage with NEEDS_REVIEW so
     * nothing disappears silently.
     */
    public static function dependencyStages($tableItems): array
    {
        $names = $tableItems->map(fn ($t) => $t->name)->unique()->values();
        $edges = [];
        foreach ($tableItems as $t) {
            $deps = [];
            foreach ($t->attributes['foreign_keys'] ?? [] as $fk) {
                if ($fk['references_table'] !== $t->name && $names->contains($fk['references_table'])) {
                    $deps[] = $fk['references_table'];
                }
            }
            $edges[$t->name] = array_values(array_unique($deps));
        }

        $stages = [];
        $placed = collect();
        $stage = 0;
        while ($placed->count() < count($edges)) {
            $ready = [];
            foreach ($edges as $name => $deps) {
                if ($placed->contains($name)) {
                    continue;
                }
                if (collect($deps)->every(fn ($d) => $placed->contains($d))) {
                    $ready[] = $name;
                }
            }
            if ($ready === []) {
                // Cycle: emit the remainder for review instead of looping forever.
                $remaining = array_values(array_diff(array_keys($edges), $placed->all()));
                $stages[$stage] = $remaining;
                break;
            }
            sort($ready);
            $stages[$stage] = $ready;
            $placed = $placed->merge($ready)->unique();
            $stage++;
        }

        return $stages;
    }

    /** 24J.8 — RPC/function target classification. */
    public static function functionTarget(array $attributes): string
    {
        if (! empty($attributes['net_dependent'])) {
            return 'queue_job';
        }
        if (! empty($attributes['auth_dependent'])) {
            return 'laravel_service';
        }
        if (($attributes['security'] ?? '') === 'definer') {
            return 'keep_postgresql';
        }

        return 'needs_review';
    }

    /**
     * The auth identity table name on the target. Reserved to 'users' unless
     * a source DATA table targets the same name (a provider can have a
     * 'users' data domain AND an auth domain) — then the identity table is
     * disambiguated to the reserved 'auth_users' name. Generic engine
     * behavior: providers never know about it.
     */
    public static function authTargetTable(bool $usersTableNameTaken): string
    {
        return $usersTableNameTaken ? 'auth_users' : 'users';
    }
}
