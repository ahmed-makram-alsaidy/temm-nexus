<?php

namespace App\Services\ControlPlane\Connectors\Contracts;

/**
 * Phase 27B.3 — the connector supports batched, resumable extraction from
 * the source. Marked separately from analysis: metadata without data
 * extraction is a legitimate connector shape.
 */
interface ExtractableSourceConnector extends SourceConnector
{
    /** Credential field keys required for data extraction. */
    public function extractionRequires(): array;

    /** Whether extraction cursors/resume tokens survive a restart (27B.3). */
    public function supportsResume(): bool;
}
