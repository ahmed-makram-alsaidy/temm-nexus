<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectEnvironment extends Model
{
    public const TYPES = ['development', 'staging', 'production'];

    protected $fillable = [
        'project_id', 'name', 'slug', 'type', 'status', 'api_base_url',
        'database_connection', 'database_secret_ref', 'redis_namespace',
        'storage_namespace', 'realtime_namespace', 'node_id',
        'is_default', 'disposable', 'config',
    ];

    protected function casts(): array
    {
        return ['database_connection' => 'array', 'config' => 'array', 'is_default' => 'boolean', 'disposable' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function secrets(): HasMany
    {
        return $this->hasMany(ProjectSecret::class);
    }

    public function isProduction(): bool
    {
        return $this->type === 'production';
    }
}
