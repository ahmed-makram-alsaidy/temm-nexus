<?php

namespace App\Services\ControlPlane\Migration;

/**
 * 24A.6 — automatic migration risk detection over an inventory.
 * Risk codes are stable strings consumed by the plan/UI and tested.
 */
class RiskDetector
{
    public const RISKS = [
        'auth_hash_strategy_unresolved',
        'rls_auth_dependency',
        'vault_dependency',
        'provider_storage_url',
        'unknown_rpc_caller',
        'edge_function_provider_secret',
        'cross_schema_dependency',
        'unsupported_extension',
        'matview_refresh_dependency',
        'hardcoded_supabase_url',
        'large_table',
        'missing_foreign_key',
        'orphan_data',
        'legacy_status_values',
        'financial_table_reconciliation',
    ];

    /** Risk codes for a single inventory item (kind + attributes). */
    public static function detect(string $kind, array $attributes = []): array
    {
        $risks = [];

        switch ($kind) {
            case 'table':
                foreach ($attributes['columns'] ?? [] as $col) {
                    if (is_string($col['default'] ?? null) && str_contains($col['default'], 'now()') === false && str_contains($col['default'], 'gen_random') === false && str_contains($col['default'], 'nextval') === false) {
                        // provider-specific default (e.g. extension call) — reviewed via fingerprint anyway
                    }
                }
                foreach ($attributes['foreign_keys'] ?? [] as $fk) {
                    $refSchema = $fk['references_schema'] ?? 'public';
                    if ($refSchema !== 'public') {
                        $risks[] = 'cross_schema_dependency';
                    }
                }
                // Heuristic: financial tables (money columns) demand exact reconciliation.
                foreach ($attributes['columns'] ?? [] as $col) {
                    if (in_array(strtolower((string) $col['type']), ['numeric', 'money'], true)) {
                        $risks[] = 'financial_table_reconciliation';
                        break;
                    }
                }
                $name = strtolower((string) ($attributes['name'] ?? ''));
                if (preg_match('/(transaction|ledger|payment|invoice|payout|wallet)/', $name)) {
                    $risks[] = 'financial_table_reconciliation';
                }
                if (empty($attributes['primary_key'])) {
                    $risks[] = 'missing_foreign_key'; // no PK → no deterministic ordering
                }
                if ((int) ($attributes['row_estimate'] ?? 0) > 1_000_000) {
                    $risks[] = 'large_table';
                }
                foreach ($attributes['dependencies'] ?? [] as $dep) {
                    if (in_array($dep, ['pg_cron', 'pg_net', 'pgvector'], true)) {
                        $risks[] = 'unsupported_extension';
                    }
                }
                break;

            case 'policy':
                foreach (['using', 'with_check'] as $exprField) {
                    $expr = strtolower((string) ($attributes[$exprField] ?? ''));
                    if (str_contains($expr, 'auth.')) {
                        $risks[] = 'rls_auth_dependency';
                    }
                    if (str_contains($expr, 'vault.')) {
                        $risks[] = 'vault_dependency';
                    }
                }
                break;

            case 'function':
                if (! empty($attributes['auth_dependent'])) {
                    $risks[] = 'rls_auth_dependency';
                }
                if (! empty($attributes['vault_dependent'])) {
                    $risks[] = 'vault_dependency';
                }
                if (empty($attributes['security']) || strtolower((string) $attributes['security']) !== 'definer') {
                    if (! empty($attributes['security'])) {
                        $risks[] = 'unknown_rpc_caller';
                    }
                }
                break;

            case 'matview':
                $risks[] = 'matview_refresh_dependency';
                break;

            case 'extension':
                if (in_array($attributes['name'] ?? '', ['pg_cron', 'pg_net', 'pgvector'], true)) {
                    $risks[] = 'unsupported_extension';
                }
                break;

            case 'storage':
                if (! empty($attributes['provider_urls'])) {
                    $risks[] = 'provider_storage_url';
                }
                break;

            case 'edge_function':
                if (! empty($attributes['secrets']) || ! empty($attributes['service_role'])) {
                    $risks[] = 'edge_function_provider_secret';
                }
                break;

            case 'auth':
                if (($attributes['hash_strategy'] ?? 'unknown') === 'unknown') {
                    $risks[] = 'auth_hash_strategy_unresolved';
                }
                break;

            case 'client':
                // Phase 27B/28R — provider URL risks come from registered
                // connector scanner providers (generic; no per-provider branch).
                $urls = $attributes['urls'] ?? [];
                $providers = \App\Services\ControlPlane\Repository\ClientDependencyScanner::providers();
                foreach ($urls as $url) {
                    $url = (string) $url;
                    if (str_contains($url, 'supabase')) { // legacy substring check (behavior preserved)
                        $risks[] = 'hardcoded_supabase_url';
                    }
                    foreach ($providers as $provider) {
                        foreach ($provider->patternsFor('common') as $pattern) {
                            if (($pattern['category'] ?? null) === 'url' && preg_match($pattern['regex'], $url) === 1) {
                                $risks[] = $provider->hardcodedUrlRiskCode();
                                break;
                            }
                        }
                    }
                }
                break;
        }

        return array_values(array_unique($risks));
    }
}
