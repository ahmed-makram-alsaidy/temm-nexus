<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadinessCheck extends Model
{
    public const STATUSES = ['green', 'yellow', 'red', 'not_applicable'];

    protected $fillable = [
        'project_id', 'environment_id', 'category', 'check_key', 'title',
        'origin', 'status', 'detail', 'evidence', 'blocks_production',
        'acknowledged_by', 'acknowledged_at', 'acknowledgement_note', 'evaluated_at',
    ];

    protected function casts(): array
    {
        return ['blocks_production' => 'boolean', 'acknowledged_at' => 'datetime', 'evaluated_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
