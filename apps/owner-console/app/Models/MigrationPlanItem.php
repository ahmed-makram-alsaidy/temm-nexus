<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationPlanItem extends Model
{
    public const STATUSES = [
        'DISCOVERED', 'MAPPED', 'READY', 'MIGRATED', 'VALIDATED', 'NEEDS_REVIEW', 'BLOCKED', 'SKIPPED_WITH_REASON',
    ];

    protected $fillable = [
        'migration_plan_id', 'source_kind', 'source_schema', 'source_name',
        'target_kind', 'target_name', 'strategy', 'transform', 'stage',
        'validation', 'status', 'skip_reason', 'order_override', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'order_override' => 'integer', 'stage' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MigrationPlan::class, 'migration_plan_id');
    }
}
