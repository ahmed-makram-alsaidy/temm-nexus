<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingSession extends Model
{
    protected $fillable = ['project_id', 'user_id', 'flow', 'status', 'current_step', 'state'];

    protected function casts(): array
    {
        return ['state' => 'array', 'current_step' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
