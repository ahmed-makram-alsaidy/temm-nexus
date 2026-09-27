<?php

namespace App\Services\ControlPlane\Migration;

use App\Models\MigrationAnalysisItem;

/**
 * 24A.5 — compatibility classification. A source object is never "fully
 * compatible" merely because it was discovered: classification is evidence-
 * based per concept (Auth, Database, RLS, Storage, Realtime, RPC, Edge
 * Functions, Cron).
 */
class CompatibilityClassifier
{
    public const DIRECT = 'DIRECT';
    public const SUPPORTED_WITH_TRANSFORM = 'SUPPORTED_WITH_TRANSFORM';
    public const APPLICATION_CONVERSION_REQUIRED = 'APPLICATION_CONVERSION_REQUIRED';
    public const EXTERNAL_INTEGRATION = 'EXTERNAL_INTEGRATION';
    public const NEEDS_REVIEW = 'NEEDS_REVIEW';
    public const BLOCKED = 'BLOCKED';
    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    /** Classify a normalized analysis item. */
    public static function classify(string $kind, array $attributes = []): array
    {
        return match ($kind) {
            'table' => self::table($attributes),
            'view' => [self::SUPPORTED_WITH_TRANSFORM, 'View rebuilt as SQL view or API query; check materialized dependencies'],
            'matview' => [self::NEEDS_REVIEW, 'Materialized view refresh strategy has no direct platform equivalent'],
            'enum' => [self::DIRECT, 'PostgreSQL enum type carries over'],
            'function' => self::functionItem($attributes),
            'trigger' => self::trigger($attributes),
            'policy' => [self::APPLICATION_CONVERSION_REQUIRED, 'RLS policy maps to Laravel authorization (Policy/Gate); review required'],
            'extension' => self::extension($attributes),
            'auth' => [self::SUPPORTED_WITH_TRANSFORM, 'Auth users migrate with UUID + hash preservation via auth template'],
            'storage' => [self::SUPPORTED_WITH_TRANSFORM, 'Buckets/objects map to platform storage with URL rewrite'],
            'realtime' => [self::APPLICATION_CONVERSION_REQUIRED, 'Realtime subscription maps to Reverb channels'],
            'cron' => [self::APPLICATION_CONVERSION_REQUIRED, 'pg_cron job maps to platform Scheduler'],
            'edge_function' => self::edgeFunction($attributes),
            'client' => [self::NEEDS_REVIEW, 'Client dependency must be re-pointed to platform SDK'],
            default => [self::NEEDS_REVIEW, 'Unclassified object kind'],
        };
    }

    private static function table(array $attributes): array
    {
        $risks = $attributes['_risks'] ?? [];
        if (in_array('unsupported_extension_dependency', $risks)) {
            return [self::BLOCKED, 'Table depends on an extension with no platform equivalent'];
        }

        return [self::DIRECT, 'PostgreSQL table migrates with schema + data copy'];
    }

    private static function functionItem(array $attributes): array
    {
        if (! empty($attributes['vault_dependent'])) {
            return [self::NEEDS_REVIEW, 'Function reads vault.* secrets — rework against platform vault'];
        }
        if (! empty($attributes['net_dependent'])) {
            return [self::EXTERNAL_INTEGRATION, 'Function performs network calls (pg_net) — becomes queued job/integration'];
        }
        if (! empty($attributes['auth_dependent'])) {
            return [self::APPLICATION_CONVERSION_REQUIRED, 'Function reads auth.* — becomes Laravel service with authenticated context'];
        }

        return [self::NEEDS_REVIEW, 'Classify: keep in PostgreSQL / Laravel service / server function / scheduler'];
    }

    private static function trigger(array $attributes): array
    {
        // Same-transaction invariants (e.g. financial posting) must stay in
        // PostgreSQL — a lesson generalized from prior conversions.
        return [self::NEEDS_REVIEW, 'Decide: keep in PostgreSQL (invariants) vs Laravel events'];
    }

    private static function extension(array $attributes): array
    {
        $name = $attributes['name'] ?? '';
        $known = ['uuid-ossp' => 'uuid', 'pgcrypto' => 'pgcrypto', 'pg_trgm' => 'pg_trgm', 'btree_gin' => 'btree_gin', 'citext' => 'citext'];
        if (isset($known[$name])) {
            return [self::DIRECT, 'Extension available on platform PostgreSQL'];
        }
        if (in_array($name, ['pg_cron', 'pg_net'], true)) {
            return [self::EXTERNAL_INTEGRATION, 'Provider scheduling/network extension — maps to Scheduler/Queues'];
        }

        return [self::NEEDS_REVIEW, 'Extension availability on platform must be verified'];
    }

    private static function edgeFunction(array $attributes): array
    {
        if (! empty($attributes['service_role'])) {
            return [self::EXTERNAL_INTEGRATION, 'Privileged edge function — re-implement as queued job/server function with vault secrets'];
        }

        return [self::APPLICATION_CONVERSION_REQUIRED, 'Re-implement as Laravel controller/server function'];
    }
}
