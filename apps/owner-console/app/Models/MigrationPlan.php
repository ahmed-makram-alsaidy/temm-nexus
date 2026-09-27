<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MigrationPlan extends Model
{
    protected $fillable = ['project_id', 'migration_analysis_id', 'environment_id', 'name', 'status', 'strategy', 'created_by'];

    protected function casts(): array
    {
        return ['strategy' => 'array'];
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(MigrationAnalysis::class, 'migration_analysis_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MigrationPlanItem::class);
    }
}
