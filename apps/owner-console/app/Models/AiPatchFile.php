<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPatchFile extends Model
{
    protected $fillable = [
        'ai_patch_run_id', 'path', 'action', 'reason', 'risk', 'diff',
        'tests', 'status', 'reviewed_by',
    ];

    public function patchRun(): BelongsTo
    {
        return $this->belongsTo(AiPatchRun::class, 'ai_patch_run_id');
    }
}
