<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership of a user in ONE project.
 *
 * This is the narrowest grant in the platform. It never implies access to the
 * owning workspace, to sibling projects, or to platform surfaces. A project
 * membership is additive to a workspace membership, never a substitute for it.
 */
class ProjectMember extends Model
{
    protected $fillable = [
        'project_id', 'user_id', 'role', 'status', 'granted_by', 'granted_at',
    ];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
