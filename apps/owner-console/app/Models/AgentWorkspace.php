<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentWorkspace extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RELEASED = 'released';

    public const STATUS_CLEANED = 'cleaned';

    protected $fillable = [
        'agent_task_id', 'project_id', 'path', 'base_revision',
        'status', 'retain_until', 'cleaned_at',
    ];

    protected function casts(): array
    {
        return [
            'retain_until' => 'datetime',
            'cleaned_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class, 'agent_task_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
