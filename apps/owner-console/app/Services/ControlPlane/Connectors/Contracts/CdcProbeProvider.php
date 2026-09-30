<?php

namespace App\Services\ControlPlane\Connectors\Contracts;

use App\Models\MigrationSource;

/**
 * Phase 32A/32B — optional connector contract: report the source's
 * change-capture readiness HONESTLY.
 *
 * Provider knowledge (which server settings enable change capture, how to
 * read them) lives INSIDE the connector package — the core only consumes
 * the normalized probe result. Probes READ configuration only; enabling
 * replication is always an operator action with connector-provided
 * instructions. Where a source cannot support robust CDC, the probe says
 * NOT_SUPPORTED — support is never faked (32E).
 *
 * @return array{mechanism: ?string, checkpoint_kind: ?string, status: string, observed: array, operator_instructions: list<string>}
 */
interface CdcProbeProvider
{
    public function cdcProbe(MigrationSource $source): array;
}
