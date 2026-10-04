<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One command the runtime executed inside the isolated workspace, captured
 * with policy metadata. Output CONTENT is never persisted — only size and
 * truncation state, so nothing secret can leak through the ledger.
 */
class AgentTaskCommand extends Model
{
    use HasUuids;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMEOUT = 'timeout';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'agent_task_id', 'command', 'cwd', 'started_at', 'exit_code',
        'duration_ms', 'output_bytes', 'truncated', 'status',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'truncated' => 'boolean'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class, 'agent_task_id');
    }
}
