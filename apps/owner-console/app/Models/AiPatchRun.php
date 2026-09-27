<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiPatchRun extends Model
{
    protected $fillable = [
        'project_id', 'copilot_run_id', 'client_repository_id', 'run_id',
        'workspace_path', 'target_root', 'status', 'approved_by', 'approved_at',
        'applied_at', 'git_status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'applied_at' => 'datetime', 'git_status' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(AiPatchFile::class);
    }
}
