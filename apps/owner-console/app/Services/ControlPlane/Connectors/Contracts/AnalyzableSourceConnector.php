<?php

namespace App\Services\ControlPlane\Connectors\Contracts;

/**
 * Phase 27B.2 — the connector implements provider-aware analysis with a
 * normalized result shape (schemas/collections/fields/policies/...).
 * Marked separately so the core can honestly report analysis support.
 */
interface AnalyzableSourceConnector extends SourceConnector
{
    /** Credential field keys required for analysis (e.g. ['db_password']). */
    public function analysisRequires(): array;
}
