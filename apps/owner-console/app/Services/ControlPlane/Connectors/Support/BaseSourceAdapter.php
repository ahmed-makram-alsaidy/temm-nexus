<?php

namespace App\Services\ControlPlane\Connectors\Support;

use App\Models\MigrationSource;
use App\Services\ControlPlane\Migration\Contracts\SourceAdapter;
use App\Services\ControlPlane\Migration\SchemaFingerprint;

/**
 * Phase 27 — shared source-adapter plumbing for connector implementations.
 *
 * Connectors plug into the Phase 24 engine through the SourceAdapter
 * contract; this base provides the generic pieces (fingerprinting, safe
 * identifier quoting, the no-auth default) so provider adapters — Supabase,
 * the SQLite rehearsal adapter, future MongoDB etc. — stay independent of
 * each other instead of subclassing a specific provider.
 */
abstract class BaseSourceAdapter implements SourceAdapter
{
    protected ?\PDO $pdo = null;
    protected MigrationSource $source;

    /** Connection details echoed by connect() — never contains secrets. */
    protected array $connection = [];

    public function __construct(MigrationSource $source)
    {
        $this->source = $source;
    }

    abstract public function connect(): void;

    abstract public function inventory(): array;

    public function fingerprint(): string
    {
        return SchemaFingerprint::compute($this->inventory());
    }

    public function streamAuthUsers(callable $callback, int $batchSize = 500): int
    {
        // Generic sources have no provider-auth domain; connectors with one
        // override this (Supabase auth schema, future Firebase, ...).
        return 0;
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    /** Quote a validated SQL identifier (never interpolate raw names). */
    protected function qi(string $identifier): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
}
