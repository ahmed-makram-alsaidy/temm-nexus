<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentTask extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_STARTING = 'starting';

    public const STATUS_RUNNING = 'running';

    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';

    public const STATUS_APPLYING = 'applying';

    public const STATUS_VERIFYING = 'verifying';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_STALE = 'stale';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_QUEUED, self::STATUS_STARTING, self::STATUS_RUNNING,
        self::STATUS_AWAITING_APPROVAL, self::STATUS_APPLYING, self::STATUS_VERIFYING,
        self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_STALE,
    ];

    /** Terminal statuses — no further transitions. */
    public const TERMINAL = [
        self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_STALE,
    ];

    /** Structured error categories (never free text). */
    public const ERROR_RUNTIME_UNAVAILABLE = 'RUNTIME_UNAVAILABLE';

    public const ERROR_RUNTIME_AUTH_FAILED = 'RUNTIME_AUTH_FAILED';

    public const ERROR_MODEL_UNAVAILABLE = 'MODEL_UNAVAILABLE';

    public const ERROR_SESSION_FAILED = 'SESSION_FAILED';

    public const ERROR_WORKSPACE_FAILED = 'WORKSPACE_FAILED';

    public const ERROR_COMMAND_FAILED = 'COMMAND_FAILED';

    public const ERROR_TASK_CANCELLED = 'TASK_CANCELLED';

    public const ERROR_INVALID_RUNTIME_RESPONSE = 'INVALID_RUNTIME_RESPONSE';

    public const ERROR_TIMEOUT = 'TIMEOUT';

    /** @var list<string> */
    public const ERROR_CATEGORIES = [
        self::ERROR_RUNTIME_UNAVAILABLE, self::ERROR_RUNTIME_AUTH_FAILED,
        self::ERROR_MODEL_UNAVAILABLE, self::ERROR_SESSION_FAILED,
        self::ERROR_WORKSPACE_FAILED, self::ERROR_COMMAND_FAILED,
        self::ERROR_TASK_CANCELLED, self::ERROR_INVALID_RUNTIME_RESPONSE,
        self::ERROR_TIMEOUT,
    ];

    protected $fillable = [
        'code', 'created_by', 'workspace_id', 'project_id', 'agent_runtime_id',
        'model', 'prompt', 'title', 'status', 'base_revision', 'agent_workspace_id',
        'runtime_session_id', 'attempts', 'error_category', 'error_message', 'usage',
        'approved_by', 'approved_at', 'applied_at', 'verified_at',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'usage' => 'array',
            'approved_at' => 'datetime',
            'applied_at' => 'datetime',
            'verified_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function runtime(): BelongsTo
    {
        return $this->belongsTo(AgentRuntime::class, 'agent_runtime_id');
    }

    public function workspaceRecord(): BelongsTo
    {
        return $this->belongsTo(AgentWorkspace::class, 'agent_workspace_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AgentTaskEvent::class)->orderBy('seq');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(AgentTaskCommand::class);
    }

    public function changesets(): HasMany
    {
        return $this->hasMany(AgentChangeset::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(AgentApproval::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(AgentVerification::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }
}
