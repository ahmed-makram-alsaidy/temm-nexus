<?php

namespace App\Services\ControlPlane\Connectors;

class ConnectorNotSupported extends \RuntimeException
{
    public function __construct(string $type)
    {
        parent::__construct("Source connector '{$type}' is not supported by this platform installation. Supported connectors: ".implode(', ', array_keys(ConnectorRegistry::all())).'.');
    }
}
