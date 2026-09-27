<?php

namespace App\Services\ControlPlane\Migration\Contracts;

use App\Models\MigrationSource;

/**
 * Read-only source adapter contract (24J.1).
 *
 * Implementations MUST be strictly read-only: analyze/inventory/preview/
 * export/stream never mutate the source. Supabase is the first adapter;
 * the platform will add more without rewriting the engine.
 */
interface SourceAdapter
{
    public function __construct(MigrationSource $source);

    /** Adapter identifier, e.g. 'supabase'. */
    public static function id(): string;

    /** Open the connection. Must throw on failure. Must enforce read-only mode. */
    public function connect(): void;

    /** Full capability inventory (24A.3). Structure is adapter-normalized. */
    public function inventory(): array;

    /** Deterministic schema fingerprint of the source. */
    public function fingerprint(): string;

    /** Exact row count for a table. */
    public function countRows(string $schema, string $table): int;

    /**
     * Stream rows in deterministic PK order. Callback receives array rows.
     * Returns the total streamed. UTF-8 safe by contract: rows must round-trip
     * byte-exact (24J.4).
     */
    public function streamRows(string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int;

    /** Stream auth users where the source has an auth domain. */
    public function streamAuthUsers(callable $callback, int $batchSize = 500): int;

    /** Close the connection. */
    public function close(): void;
}
