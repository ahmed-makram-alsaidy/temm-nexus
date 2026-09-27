<?php

namespace App\Services\ControlPlane\Connectors;

/**
 * Phase 27D.3 — two connectors declared the same stable key. Registration is
 * refused so first-party connectors can never be silently shadowed.
 */
final class ConnectorKeyConflict extends \RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct("A connector with key '{$key}' is already registered.");
    }
}
