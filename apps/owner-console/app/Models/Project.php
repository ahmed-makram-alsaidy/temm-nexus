<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        // 0.4.0 workspace pointer (nullable: legacy projects stay valid).
        'workspace_id',
        'name', 'slug', 'domain', 'api_domain', 'status',
        'db_name', 'redis_prefix', 'storage_disk', 'deploy_status',
        'environment', 'timezone', 'locale', 'maintenance_mode',
        'last_deployed_at', 'health_status', 'api_version', 'notes',
        // Phase 21B per-project endpoint overrides (NULL = env defaults).
        'db_host', 'db_port', 'redis_host', 'redis_port',
        'reverb_host', 'reverb_port', 'infra_profile',
    ];

    protected function casts(): array
    {
        return [
            'maintenance_mode' => 'boolean',
            'last_deployed_at' => 'datetime',
        ];
    }

    public function routeSlug(): string
    {
        return $this->slug;
    }

    public function environments(): HasMany
    {
        return $this->hasMany(ProjectEnvironment::class);
    }

    public function clientRepositories(): HasMany
    {
        return $this->hasMany(ClientRepository::class);
    }

    // ── 0.4.0 workspace scope ──────────────────────────────────────────

    /**
     * The owning workspace. NULL means this project predates workspaces and
     * has not been assigned yet — the UI shows it as "Ungrouped" and it
     * remains fully functional.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    /** @return HasMany<ProjectMember, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot(['role', 'status', 'granted_at'])
            ->withTimestamps();
    }

    public function isGrouped(): bool
    {
        return $this->workspace_id !== null;
    }

    /** Product-language health label; never exposes the raw token. */
    public function healthLabel(): string
    {
        return match ($this->health_status) {
            'healthy' => 'Healthy',
            'degraded' => 'Degraded',
            'unhealthy' => 'Needs attention',
            default => 'Unknown',
        };
    }
}
