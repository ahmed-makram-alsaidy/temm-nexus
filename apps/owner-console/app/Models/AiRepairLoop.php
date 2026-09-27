<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRepairLoop extends Model
{
    protected $fillable = [
        'project_id', 'ai_patch_run_id', 'test_command', 'max_iterations',
        'max_ai_calls', 'iterations', 'ai_calls', 'status', 'history',
    ];

    protected function casts(): array
    {
        return ['history' => 'array', 'iterations' => 'integer', 'ai_calls' => 'integer',
            'max_iterations' => 'integer', 'max_ai_calls' => 'integer'];
    }

    public function patchRun(): BelongsTo
    {
        return $this->belongsTo(AiPatchRun::class, 'ai_patch_run_id');
    }
}
