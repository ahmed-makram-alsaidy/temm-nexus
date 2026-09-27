<?php

namespace App\Services\ControlPlane\Connectors\Contracts;

/**
 * Phase 27B.4 — the connector contributes provider-aware validation
 * artifacts (counts, checksums, consistency checks) to the validation suite.
 */
interface ValidatableSourceConnector extends SourceConnector
{
    /** Validator names contributed by this connector (besides the generic suite). */
    public function providedValidators(): array;
}
