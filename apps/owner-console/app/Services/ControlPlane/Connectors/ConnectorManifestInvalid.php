<?php

namespace App\Services\ControlPlane\Connectors;

/**
 * Phase 27J.3 — a connector manifest failed validation. Carries every
 * problem found so operators/developers see the full picture at once.
 */
final class ConnectorManifestInvalid extends \RuntimeException
{
    /** @param list<string> $errors */
    public static function with(array $errors, string $path = 'connector.json'): self
    {
        return new self(
            'Invalid connector manifest ('.$path.'): '.implode('; ', $errors)
        );
    }
}
