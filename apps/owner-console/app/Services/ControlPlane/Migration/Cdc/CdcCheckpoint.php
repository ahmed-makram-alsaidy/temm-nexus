<?php

namespace App\Services\ControlPlane\Migration\Cdc;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 32F — persisted CDC/incremental checkpoint (see the phase32
 * migration for the schema). Position payloads are HMAC-signed and
 * verified on load — a tampered checkpoint is refused, never trusted.
 *
 * Phase 35.6 — carries normalized stream telemetry beside the signed
 * position: stream_status / last_event_at / stream_telemetry. Telemetry is
 * deliberately NOT part of the signature (it is measurement, not position);
 * the position payload itself is what tamper protection guards.
 */
class CdcCheckpoint extends Model
{
    protected $table = 'cdc_checkpoints';

    protected $fillable = [
        'migration_run_id', 'source_type', 'target_key', 'kind',
        'position', 'signature', 'applied_events', 'lag_events',
        'last_reconciliation', 'errors',
        'stream_status', 'last_event_at', 'stream_telemetry',
    ];

    protected $casts = [
        'position' => 'array',
        'last_reconciliation' => 'array',
        'errors' => 'array',
        'stream_telemetry' => 'array',
        'applied_events' => 'integer',
        'lag_events' => 'integer',
        'last_event_at' => 'datetime',
    ];
}
