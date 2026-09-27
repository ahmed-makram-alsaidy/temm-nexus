<?php

namespace App\Services\ControlPlane\Connectors\Contracts;

/**
 * Phase 27B — client-repository scanning patterns contributed by a connector.
 *
 * The generic client scanner detects provider SDK idioms and embedded
 * secrets in the operator's application repository. Each connector may
 * contribute its own pattern set; the scanner merges providers and stores
 * evidence HASHED only — raw secret material never enters the database,
 * logs or AI context.
 */
interface ClientScannerProvider
{
    /** Display label of the provider (used in UI labels). */
    public function scannerLabel(): string;

    /**
     * Effective callsite patterns for one language ('dart', 'javascript',
     * 'typescript', 'php', 'common'): list of
     * `['category' => ..., 'regex' => ..., 'target' => capture-index|null]`.
     *
     * @return list<array{category: string, regex: string, target: int|null}>
     */
    public function patternsFor(string $language): array;

    /** Env/config secret markers (names only — values are never stored). */
    public function secretMarkers(): array;

    /** Provider config directory names worth noting in repo inventory (e.g. ['supabase']). */
    public function configDirNames(): array;

    /** Risk code emitted for hardcoded provider URLs (e.g. 'hardcoded_supabase_url'). */
    public function hardcodedUrlRiskCode(): string;
}
