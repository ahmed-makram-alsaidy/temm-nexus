<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 21A: persisted ERD node layout per project/user/schema.
 */
class ErdLayout extends Model
{
    protected $fillable = ['project_id', 'user_id', 'schema', 'layout'];

    protected function casts(): array
    {
        return ['layout' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
