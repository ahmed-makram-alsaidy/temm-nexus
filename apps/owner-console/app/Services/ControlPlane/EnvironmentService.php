<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectSecret;
use App\Models\SchemaSnapshot;
use Illuminate\Support\Str;

/**
 * Phase 24C — canonical per-project environment model.
 *
 * Every project gets Development / Staging / Production environment records.
 * Isolation contract (proven in tests): each environment carries its own
 * database connection coordinates, redis/storage/realtime namespaces, secrets
 * and logs. Nothing copies sensitive data between environments automatically.
 */
class EnvironmentService
{
    /** Ensure the three canonical environments exist for a project. */
    public static function ensureDefaults(Project $project): void
    {
        $existing = $project->environments()->pluck('slug')->all();
        foreach ([
            ['name' => 'Development', 'slug' => 'development', 'type' => 'development', 'disposable' => true],
            ['name' => 'Staging', 'slug' => 'staging', 'type' => 'staging', 'disposable' => false],
            ['name' => 'Production', 'slug' => 'production', 'type' => 'production', 'disposable' => false],
        ] as $i => $spec) {
            if (in_array($spec['slug'], $existing, true)) {
                continue;
            }
            // The unique project/slug constraint reconciles concurrent creation.
            ProjectEnvironment::createOrFirst(
                ['project_id' => $project->id, 'slug' => $spec['slug']],
                [
                    'name' => $spec['name'],
                    'type' => $spec['type'],
                    'status' => $i === 0 ? 'active' : 'inactive',
                    'is_default' => $i === 0,
                    'disposable' => $spec['disposable'],
                    'redis_namespace' => $project->redis_prefix ? $project->redis_prefix.'_'.$spec['slug'] : null,
                    'storage_namespace' => $project->slug.'-'.$spec['slug'],
                    'realtime_namespace' => $project->slug.'-'.$spec['slug'],
                ]
            );
        }
    }

    /** Namespaced redis prefix for an environment (prefix isolation proof). */
    public static function redisNamespace(Project $project, ProjectEnvironment $env): string
    {
        return $env->redis_namespace ?: ($project->redis_prefix ?: $project->slug).'_'.$env->slug;
    }

    public static function create(Project $project, array $data): ProjectEnvironment
    {
        $type = $data['type'] ?? 'development';
        abort_unless(in_array($type, ProjectEnvironment::TYPES, true), 422, 'Unknown environment type');

        $env = ProjectEnvironment::create([
            'project_id' => $project->id,
            'name' => $data['name'],
            'slug' => Str::slug($data['slug'] ?? $data['name']),
            'type' => $type,
            'status' => $data['status'] ?? 'active',
            'api_base_url' => $data['api_base_url'] ?? null,
            'database_connection' => $data['database_connection'] ?? null,
            'database_secret_ref' => $data['database_secret_ref'] ?? null,
            'redis_namespace' => $data['redis_namespace'] ?? ($project->redis_prefix ? $project->redis_prefix.'_'.Str::slug($data['slug'] ?? $data['name']) : null),
            'storage_namespace' => $data['storage_namespace'] ?? ($project->slug.'-'.Str::slug($data['slug'] ?? $data['name'])),
            'realtime_namespace' => $data['realtime_namespace'] ?? ($project->slug.'-'.Str::slug($data['slug'] ?? $data['name'])),
            'node_id' => $data['node_id'] ?? null,
            'is_default' => false,
            'disposable' => (bool) ($data['disposable'] ?? $type === 'development'),
        ]);

        AdminAudit::record('ENVIRONMENT_CREATED', $project, 'environment', $env->id, [
            'name' => $env->name, 'type' => $env->type,
        ]);

        return $env;
    }

    public static function update(ProjectEnvironment $env, array $data): void
    {
        // Type can never be changed to dodge guardrails (e.g. marking
        // production as development to slip past the disposable guard).
        unset($data['type'], $data['project_id']);
        $env->update($data);
        AdminAudit::record('ENVIRONMENT_UPDATED', $env->project, 'environment', $env->id, [
            'fields' => array_keys($data),
        ]);
    }

    /** Default (fallback) environment of a project. */
    public static function defaultFor(Project $project): ProjectEnvironment
    {
        self::ensureDefaults($project);

        return $project->environments()->orderByDesc('is_default')->orderBy('id')->firstOrFail();
    }

    /**
     * Promotion guard: staging→production promotion requires explicit strong
     * confirmation and is local-only for now. Never copies sensitive data.
     */
    public static function attemptPromotion(ProjectEnvironment $from, ProjectEnvironment $to, bool $confirmed, array $context = []): array
    {
        $checks = [
            'confirmed' => $confirmed,
            'source_not_production' => $from->type !== 'production',
            'target_is_production' => $to->type === 'production',
            'source_status_active' => $from->status === 'active',
            'target_status_active' => $to->status === 'active',
        ];
        if ($to->type === 'production') {
            // Strong confirmation must carry the literal project slug.
            $checks['strong_confirmation'] = ($context['confirmation'] ?? '') === 'PROMOTE '.$from->project->slug.' TO PRODUCTION';
        }
        $passed = ! in_array(false, $checks, true);

        AdminAudit::record('PROMOTION_ATTEMPTED', $from->project, 'environment', $to->id, [
            'from' => $from->slug, 'to' => $to->slug, 'result' => $passed ? 'allowed' : 'blocked',
        ]);

        return ['allowed' => $passed, 'checks' => $checks];
    }

    /**
     * Environment diff (24C.5): deployment version, schema fingerprint,
     * secrets completeness, config. Honest about what cannot be compared.
     */
    public static function diff(ProjectEnvironment $a, ProjectEnvironment $b): array
    {
        $rows = [];
        $rows[] = ['field' => 'type', 'a' => $a->type, 'b' => $b->type, 'same' => $a->type === $b->type];
        $rows[] = ['field' => 'status', 'a' => $a->status, 'b' => $b->status, 'same' => $a->status === $b->status];
        $rows[] = ['field' => 'api_base_url', 'a' => $a->api_base_url, 'b' => $b->api_base_url, 'same' => $a->api_base_url === $b->api_base_url];

        $fpA = SchemaSnapshot::where('environment_id', $a->id)->orderByDesc('id')->value('fingerprint');
        $fpB = SchemaSnapshot::where('environment_id', $b->id)->orderByDesc('id')->value('fingerprint');
        $rows[] = [
            'field' => 'schema_fingerprint',
            'a' => $fpA ? substr($fpA, 0, 12) : '(no snapshot)',
            'b' => $fpB ? substr($fpB, 0, 12) : '(no snapshot)',
            'same' => $fpA !== null && $fpA === $fpB,
        ];

        $secretsA = ProjectSecret::where('environment_id', $a->id)->pluck('name');
        $secretsB = ProjectSecret::where('environment_id', $b->id)->pluck('name');
        $rows[] = [
            'field' => 'secrets_completeness',
            'a' => $secretsA->count().' secrets',
            'b' => $secretsB->count().' secrets',
            'same' => $secretsA->sort()->values()->all() === $secretsB->sort()->values()->all(),
        ];

        $rows[] = [
            'field' => 'config',
            'a' => count($a->config ?? []).' keys',
            'b' => count($b->config ?? []).' keys',
            'same' => ($a->config ?? []) === ($b->config ?? []),
        ];

        return $rows;
    }
}
