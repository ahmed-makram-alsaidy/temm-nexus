<?php

namespace App\Services\ControlPlane\Connectors\Contracts;

use App\Models\MigrationSource;
use App\Models\Project;
use App\Services\ControlPlane\Connectors\ConnectorCredentials;
use App\Services\ControlPlane\Migration\Contracts\SourceAdapter;

/**
 * Phase 27B.1 — a migration SOURCE connector.
 *
 * Extends the base connector with the full source lifecycle the Migration
 * Center consumes: discover → select → probe → analyze → extract → validate.
 * Implementations must keep every operation strictly read-only against the
 * source (27H.3) and return normalized, provider-agnostic structures
 * (27B.2) — provider-specific extras stay inside `metadata`/`extensions`.
 */
interface SourceConnector extends Connector
{
    /**
     * Discover selectable projects/datasets at the account/provider level
     * (27B.1). Returns a list of `['ref' => ..., 'name' => ..., plus
     * connector-specific safe metadata]`. Receives ONLY the credentials the
     * operation needs (27E.3).
     *
     * @return list<array<string, mixed>>
     */
    public function discoverProjects(ConnectorCredentials $credentials): array;

    /**
     * Create the platform-side MigrationSource for a selection (27F
     * lifecycle "Select Source"). Sources are created read-only with secret
     * REFS only — plaintext credentials must never land on the model.
     */
    public function createSourceProfile(Project $project, array $selection, array $configuration, ?int $environmentId = null): MigrationSource;

    /**
     * The read-only engine adapter for a configured source (Phase 24
     * SourceAdapter contract — connect/inventory/fingerprint/stream/close).
     * This is the seam the Migration Center runs on.
     */
    public function sourceAdapter(MigrationSource $source): SourceAdapter;

    /**
     * 27B.2 — normalized analysis inventory for a source (same normalized
     * shape as SourceAdapter::inventory(): schemas, tables, views, functions,
     * policies, auth, storage, realtime, ...). Implementations may override
     * for provider-tuned analysis; the default delegates to the adapter.
     */
    public function analyze(MigrationSource $source): array;

    /**
     * 27B.3 — extract records from one collection (read-only, batched,
     * callback-driven; never loads the whole source into memory). Returns the
     * number of extracted records. Default delegates to the adapter.
     */
    public function extract(MigrationSource $source, string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int;

    /**
     * 27B.4 — connector-provided validation artifacts for a source
     * (row counts, checksums, relationship checks, consistency probes).
     *
     * @return array<string, mixed>
     */
    public function validateSource(MigrationSource $source): array;

    /** Deterministic source fingerprint (27B.1) — default delegates to the adapter. */
    public function fingerprint(MigrationSource $source): string;
}
