<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 21B: first-class infrastructure node. A node reports health outbound
 * to the Control Plane (heartbeat); the Control Plane never shells into it.
 */
class InfrastructureNode extends Model
{
    public const ROLES = [
        'proxy', 'application', 'database', 'redis',
        'queue_worker', 'realtime', 'monitoring', 'backup',
    ];

    public const STATUSES = ['healthy', 'degraded', 'offline', 'unknown'];

    /** Seconds without a heartbeat before computed status degrades. */
    public const DEGRADED_AFTER_S = 90;

    /** Seconds without a heartbeat before computed status goes offline. */
    public const OFFLINE_AFTER_S = 300;

    protected $fillable = [
        'name', 'hostname', 'environment', 'roles', 'status',
        'provider', 'region', 'cpu_cores', 'ram_mb', 'disk_gb',
        'cpu_pct', 'ram_pct', 'disk_pct', 'agent_version',
        'enabled', 'token_prefix', 'token_hash', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'enabled' => 'boolean',
            'cpu_pct' => 'float',
            'ram_pct' => 'float',
            'disk_pct' => 'float',
            'last_seen_at' => 'datetime',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(InfrastructureService::class, 'node_id');
    }

    /**
     * Liveness derived from the last heartbeat — never trust a stale stored
     * status. Disabled nodes always report offline.
     */
    public function computedStatus(): string
    {
        if (! $this->enabled) {
            return 'offline';
        }
        if (! $this->last_seen_at) {
            return 'unknown';
        }
        // Timestamp arithmetic (not Carbon diff helpers, whose signedness
        // defaults differ across Carbon major versions).
        $age = now()->timestamp - $this->last_seen_at->timestamp;
        if ($age > self::OFFLINE_AFTER_S) {
            return 'offline';
        }
        if ($age > self::DEGRADED_AFTER_S) {
            return 'degraded';
        }

        return 'healthy';
    }

    /** Mint a new agent token. Returns plaintext ONCE; only the hash is stored. */
    public function rotateToken(): string
    {
        $plain = 'nd_'.bin2hex(random_bytes(24));
        $this->forceFill([
            'token_prefix' => substr($plain, 0, 12),
            'token_hash' => hash('sha256', $plain),
        ])->save();

        return $plain;
    }

    public function tokenMatches(string $plain): bool
    {
        if (! $this->token_hash) {
            return false;
        }

        return hash_equals($this->token_hash, hash('sha256', $plain));
    }
}
