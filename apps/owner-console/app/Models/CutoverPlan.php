<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 34 — a Cutover Center plan (34A): ordered steps, honest gate
 * states, rollback plan, explicit approvals and a full event audit.
 */
class CutoverPlan extends Model
{
    protected $fillable = [
        'project_id', 'migration_run_id', 'run_id', 'status',
        'gates', 'steps', 'rollback', 'cutover_at', 'rollback_expiry',
    ];

    protected $casts = [
        'gates' => 'array',
        'steps' => 'array',
        'rollback' => 'array',
        'cutover_at' => 'datetime',
        'rollback_expiry' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(CutoverApproval::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CutoverEvent::class);
    }
}
