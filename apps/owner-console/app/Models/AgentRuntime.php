<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRuntime extends Model
{
    use HasUuids;

    public const DRIVER_OPENCODE = 'opencode';

    public const MODE_MANAGED = 'managed';

    public const MODE_EXTERNAL = 'external';

    public const STATUS_UNTESTED = 'untested';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    /** @var list<string> */
    public const MODES = [self::MODE_MANAGED, self::MODE_EXTERNAL];

    /** @var list<string> */
    public const STATUSES = [self::STATUS_UNTESTED, self::STATUS_CONNECTED, self::STATUS_ERROR];

    protected $fillable = [
        'driver', 'display_name', 'mode', 'endpoint', 'auth_secret_encrypted',
        'enabled', 'status', 'version', 'capabilities', 'default_model',
        'timeout_seconds', 'max_concurrent_tasks', 'workspace_retention_days',
        'last_tested_at', 'last_test_status', 'last_test_message', 'last_error_category',
    ];

    protected function casts(): array
    {
        return [
            'auth_secret_encrypted' => 'encrypted',
            'capabilities' => 'array',
            'enabled' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AgentTask::class);
    }

    /** Resolved base endpoint: external uses the stored one; managed derives the in-stack service URL. */
    public function resolvedEndpoint(): ?string
    {
        if ($this->mode === self::MODE_EXTERNAL) {
            return $this->endpoint;
        }

        return config('agent.managed_opencode.endpoint');
    }

    /** Resolved basic-auth password: external stores its own; managed uses the shared stack secret. */
    public function resolvedAuthSecret(): ?string
    {
        if ($this->mode === self::MODE_EXTERNAL) {
            return $this->auth_secret_encrypted;
        }

        return config('agent.managed_opencode.password') ?: null;
    }

    public function resolvedAuthUser(): string
    {
        return config('agent.managed_opencode.username', 'opencode');
    }
}
