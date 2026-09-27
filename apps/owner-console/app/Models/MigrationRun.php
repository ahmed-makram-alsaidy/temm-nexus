<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MigrationRun extends Model
{
    protected $fillable = [
        'project_id', 'migration_plan_id', 'run_id', 'dry_run', 'mode',
        'target_connection', 'target_disposable', 'status', 'started_at',
        'finished_at', 'progress', 'failure', 'created_by',
    ];

    protected function casts(): array
    {
        return ['dry_run' => 'boolean', 'target_disposable' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'progress' => 'array', 'failure' => 'array'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MigrationPlan::class, 'migration_plan_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MigrationRunItem::class);
    }
}
