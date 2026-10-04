<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentChangeset extends Model
{
    use HasUuids;

    protected $fillable = [
        'agent_task_id', 'base_revision', 'fingerprint', 'diff',
        'files_added', 'files_modified', 'files_deleted',
        'additions', 'deletions', 'truncated',
    ];

    protected function casts(): array
    {
        return ['truncated' => 'boolean'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class, 'agent_task_id');
    }
}
