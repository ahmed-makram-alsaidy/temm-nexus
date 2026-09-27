<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationRunItem extends Model
{
    protected $fillable = [
        'migration_run_id', 'migration_plan_item_id', 'stage', 'status',
        'rows_written', 'validation', 'error', 'attempts', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['validation' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'rows_written' => 'integer'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(MigrationRun::class, 'migration_run_id');
    }

    public function planItem(): BelongsTo
    {
        return $this->belongsTo(MigrationPlanItem::class, 'migration_plan_item_id');
    }
}
