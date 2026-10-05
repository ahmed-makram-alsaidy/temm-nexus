<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MigrationAnalysis extends Model
{
    protected $fillable = [
        'project_id', 'migration_source_id', 'run_id', 'status', 'started_at',
        'completed_at', 'counts', 'warnings', 'errors', 'source_fingerprint', 'driver_version',
        // 0.6.0 Phase E (§E7) — real stage telemetry (Prepared/Inspecting/…).
        'telemetry',
        // Phase 27I.2 — connector traceability.
        'connector_key', 'connector_version', 'analysis_version',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime', 'completed_at' => 'datetime',
            'counts' => 'array', 'warnings' => 'array', 'errors' => 'array',
            'telemetry' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(MigrationSource::class, 'migration_source_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MigrationAnalysisItem::class);
    }
}
