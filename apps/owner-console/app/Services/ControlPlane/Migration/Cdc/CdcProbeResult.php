<?php

namespace App\Services\ControlPlane\Migration\Cdc;

/**
 * Phase 32A — normalized shape builder for connector CDC probes. Carries NO
 * provider knowledge: connectors construct the result, the core renders it.
 */
final class CdcProbeResult
{
    /** @param  list<string>  $instructions */
    public static function make(
        ?string $mechanism,
        ?string $checkpointKind,
        string $status,
        array $observed = [],
        array $instructions = [],
    ): array {
        if (! in_array($status, ['SUPPORTED', 'SUPPORTED_WITH_CONFIGURATION', 'PARTIAL', 'NOT_SUPPORTED'], true)) {
            throw new \InvalidArgumentException('probe status must be an honest ConnectorCapability status');
        }

        return [
            'mechanism' => $mechanism,
            'checkpoint_kind' => $checkpointKind,
            'status' => $status,
            'observed' => $observed,
            'operator_instructions' => $instructions,
        ];
    }
}
