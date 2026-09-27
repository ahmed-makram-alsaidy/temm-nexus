<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 21B: a managed service (PostgreSQL, Redis, Caddy, Laravel API,
 * Horizon, Reverb, Backup Worker) assigned to a node. Records carry endpoint
 * references and health only — never credentials.
 */
class InfrastructureService extends Model
{
    public const KEYS = [
        'postgres', 'redis', 'caddy', 'laravel-api',
        'horizon', 'reverb', 'backup-worker',
    ];

    protected $fillable = [
        'node_id', 'key', 'label', 'scope', 'project_id',
        'status', 'endpoint', 'health', 'version', 'last_check_at', 'config',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'last_check_at' => 'datetime',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(InfrastructureNode::class, 'node_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
