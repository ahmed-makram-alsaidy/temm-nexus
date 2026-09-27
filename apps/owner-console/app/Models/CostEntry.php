<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostEntry extends Model
{
    protected $fillable = ['project_id', 'name', 'monthly_cost', 'currency', 'allocation', 'allocated_cost', 'month', 'note'];

    protected function casts(): array
    {
        return ['monthly_cost' => 'float', 'allocated_cost' => 'float'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
