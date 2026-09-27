<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CopilotRun extends Model
{
    public const MODES = ['advisor', 'builder', 'validator'];

    protected $fillable = [
        'project_id', 'migration_analysis_id', 'client_repository_id', 'run_id',
        'mode', 'action', 'provider', 'model', 'status', 'input_refs',
        'result', 'tool_calls', 'created_by',
    ];

    protected function casts(): array
    {
        return ['input_refs' => 'array', 'result' => 'array', 'tool_calls' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
