<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One normalized runtime event for a task. Payloads are bounded and safe:
 * never raw Authorization material, never hidden chain-of-thought — only
 * user-safe summaries and tool/activity metadata.
 */
class AgentTaskEvent extends Model
{
    use HasUuids;

    public const TYPE_STATUS = 'status';

    public const TYPE_THINKING = 'thinking';

    public const TYPE_READING = 'reading';

    public const TYPE_EDITING = 'editing';

    public const TYPE_FILE_CHANGED = 'file_changed';

    public const TYPE_COMMAND = 'command';

    public const TYPE_TESTING = 'testing';

    public const TYPE_PLAN = 'plan';

    public const TYPE_PERMISSION = 'permission';

    public const TYPE_MESSAGE = 'message';

    public const TYPE_ERROR = 'error';

    public const TYPE_COMPLETED = 'completed';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_STATUS, self::TYPE_THINKING, self::TYPE_READING, self::TYPE_EDITING,
        self::TYPE_FILE_CHANGED, self::TYPE_COMMAND, self::TYPE_TESTING, self::TYPE_PLAN,
        self::TYPE_PERMISSION, self::TYPE_MESSAGE, self::TYPE_ERROR, self::TYPE_COMPLETED,
    ];

    protected $fillable = ['agent_task_id', 'seq', 'type', 'summary', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class, 'agent_task_id');
    }
}
