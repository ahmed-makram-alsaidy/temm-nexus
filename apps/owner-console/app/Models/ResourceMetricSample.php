<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceMetricSample extends Model
{
    protected $fillable = [
        'project_id', 'environment_id', 'metric', 'value', 'unit', 'attribution', 'sampled_at',
    ];

    protected function casts(): array
    {
        return ['sampled_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
