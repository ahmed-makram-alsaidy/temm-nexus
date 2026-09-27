<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadinessSnapshot extends Model
{
    protected $fillable = ['project_id', 'environment_id', 'summary', 'created_by'];

    protected function casts(): array
    {
        return ['summary' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
