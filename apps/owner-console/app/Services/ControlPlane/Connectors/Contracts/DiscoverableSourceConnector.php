<?php

namespace App\Services\ControlPlane\Connectors\Contracts;

/**
 * Phase 27B — capability refinement: the connector supports account/project
 * discovery before any source profile exists. The wizard uses this to decide
 * whether an account-connect step applies.
 */
interface DiscoverableSourceConnector extends SourceConnector
{
    /** Credential field keys required for discovery (e.g. ['pat']). */
    public function discoveryRequires(): array;
}
