<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectApiKey extends Model
{
    protected $fillable = [
        'project_id', 'name', 'prefix', 'key_hash', 'scopes',
        'expires_at', 'last_used_at', 'revoked_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array', 'expires_at' => 'datetime',
            'last_used_at' => 'datetime', 'revoked_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function project(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
