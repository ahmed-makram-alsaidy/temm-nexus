<?php

namespace App\Connectors\Supabase;

use App\Models\ClientRepository;
use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\ConnectorCapabilityProbe;

/**
 * Phase 25B.1 / 25C.4 — capability matrix for a Supabase migration source
 * (Phase 27H: lives inside the Supabase connector package).
 *
 * Each access channel is reported honestly: CONNECTED (usable),
 * NEEDS_CREDENTIAL (operator must supply), PARTIAL (some domains readable),
 * NOT_LINKED. Nothing is claimed COMPLETE when permissions prevent reading.
 * The live probe delegates to the generic connector probe, which derives the
 * same domain view from the connector's normalized inventory.
 */
class SourceCapabilityService
{
    public const CHANNELS = [
        'account_discovery', 'database', 'auth', 'storage', 'functions', 'source_code',
    ];

    public static function matrix(MigrationSource $source): array
    {
        $accountConnected = $source->external_account_connection_id !== null
            && $source->externalAccountConnection?->status === 'connected';
        $hasDb = ! empty($source->secret_refs['password']) && ! empty($source->connection['host']);

        $matrix = [
            ['channel' => 'Account discovery', 'key' => 'account_discovery', 'status' => $accountConnected ? 'CONNECTED' : 'NOT_CONNECTED', 'detail' => $accountConnected ? 'management API' : 'connect a Supabase account'],
            ['channel' => 'Database', 'key' => 'database', 'status' => $hasDb ? 'CONNECTED' : 'NEEDS_CREDENTIAL', 'detail' => $hasDb ? 'read-only PostgreSQL credential stored' : 'supply read-only DB credential'],
            ['channel' => 'Auth', 'key' => 'auth', 'status' => $hasDb ? 'VIA_DATABASE' : 'NEEDS_CREDENTIAL', 'detail' => $hasDb ? 'auth schema (read-only)' : 'requires database credential'],
            ['channel' => 'Storage', 'key' => 'storage', 'status' => $hasDb ? 'VIA_DATABASE' : 'NEEDS_CREDENTIAL', 'detail' => $hasDb ? 'storage schema (read-only)' : 'requires database credential'],
            ['channel' => 'Functions/RPC', 'key' => 'functions', 'status' => $hasDb ? 'VIA_DATABASE' : 'NEEDS_CREDENTIAL', 'detail' => $hasDb ? 'pg_proc (read-only)' : 'requires database credential'],
            ['channel' => 'Client repository', 'key' => 'source_code', 'status' => ClientRepository::where('project_id', $source->project_id)->exists() ? 'CONNECTED' : 'NOT_LINKED', 'detail' => ClientRepository::where('project_id', $source->project_id)->exists() ? 'repository linked' : 'link the client repository'],
        ];

        return $matrix;
    }

    /**
     * 25C.4 — safe capability probe against the source DB (read-only).
     * Reports PASS / PARTIAL per domain; never COMPLETE without evidence.
     * Phase 27 — the probe itself is connector-generic.
     */
    public static function probe(MigrationSource $source): array
    {
        return ConnectorCapabilityProbe::probe($source);
    }
}
