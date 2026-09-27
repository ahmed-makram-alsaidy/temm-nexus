<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientRepository extends Model
{
    protected $fillable = [
        'project_id', 'display_name', 'source_type', 'root_path', 'git_url',
        'git_branch', 'credential_ref', 'framework', 'status', 'inventory',
        'last_scanned_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['inventory' => 'array', 'last_scanned_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function callsites(): HasMany
    {
        return $this->hasMany(ClientCallsite::class);
    }
}
