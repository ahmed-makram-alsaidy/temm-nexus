<?php

namespace App\Services\ControlPlane\Connectors;

/**
 * Phase 27D.1 — the connector exists but is disabled (unverified trust or
 * operator action). Invocation is refused — disabled connectors must be
 * unreachable, not merely hidden.
 */
final class ConnectorDisabled extends \RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct("Connector '{$key}' is disabled and cannot be invoked.");
    }
}
