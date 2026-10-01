<?php

namespace App\Services\ControlPlane\Migration\Cdc;

/**
 * Phase 35.6 — the position capability of a LOG-BASED capture.
 *
 * Implemented by captures that read a real source log (WAL / binlog /
 * change stream). The generic layer uses it for two things only:
 *
 *  1. the FINAL SYNC (capture the current source position as the freeze
 *     boundary, then verify the applied side has reached it), and
 *  2. NORMALIZED LAG METRICS for the Cutover Center (the generic surface
 *     never learns what an LSN, a GTID set or a resume token is).
 *
 * Positions are provider-native payloads serialized inside checkpoints;
 * they are never comparable across providers and never interpreted here.
 */
interface CdcPositionSource
{
    /**
     * The CURRENT source position — the freeze boundary for a final sync.
     *
     * @return array{
     *     position: array<string, mixed>,   // provider-native payload (checkpoint-shaped)
     *     label: string,                    // human display, no credentials
     *     captured_at: string,              // ISO-8601
     * }
     */
    public function currentSourcePosition(): array;

    /**
     * Has the applied side reached the given source position? The provider
     * compares its own position kinds (LSN ordering, GTID set containment,
     * cluster time) — the generic layer only asks the question.
     */
    public function hasAppliedThrough(?array $checkpoint, array $sourcePosition): bool;

    /**
     * Normalized lag metrics. Generic field names only — provider detail
     * may appear under 'detail' and is display-only.
     *
     * @return array{
     *     status: string,                   // active|caught_up|idle|disconnected|error
     *     source_position_label: ?string,   // how far the SOURCE log has advanced
     *     captured_position_label: ?string, // how far the READER has captured
     *     applied_position_label: ?string,  // how far the TARGET has applied
     *     lag_seconds: ?float,              // source commit → now, when derivable
     *     lag_events: ?int,                 // committed changes not yet applied
     *     last_event_at: ?string,           // ISO-8601 source timestamp of last change
     *     detail: array<string, mixed>,
     * }
     */
    public function lagSnapshot(?array $checkpoint): array;
}
