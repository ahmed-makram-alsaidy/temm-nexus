<?php

namespace App\Models;

use App\Services\Access\Roles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Workspace is an organisational boundary: a client, a company, an internal
 * team, or any other grouping that owns one or more Projects.
 *
 *     PLATFORM └─ WORKSPACE └─ PROJECT
 *
 * A workspace never holds customer data itself; it scopes access to the
 * projects inside it. Deleting a workspace cascades to its memberships but the
 * `projects.workspace_id` column has no FK, so projects survive as
 * "Ungrouped" rather than being destroyed.
 */
class Workspace extends Model
{
    protected $fillable = [
        'name', 'slug', 'kind', 'status', 'description',
        'primary_contact_name', 'primary_contact_email',
        'timezone', 'locale', 'color',
        'health_status', 'health_checked_at', 'settings', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_default' => 'boolean',
            'health_checked_at' => 'datetime',
        ];
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'workspace_id');
    }

    /** @return HasMany<WorkspaceMember, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot(['role', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function owner(): ?User
    {
        $membership = $this->memberships()
            ->where('role', Roles::WORKSPACE_OWNER)
            ->where('status', 'active')
            ->first();

        return $membership?->user;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Navigable identity for UI. Never null. */
    public function displayKind(): string
    {
        return match ($this->kind) {
            'client' => 'Client',
            'company' => 'Company',
            'team' => 'Team',
            'internal' => 'Internal',
            default => ucfirst((string) $this->kind),
        };
    }

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
