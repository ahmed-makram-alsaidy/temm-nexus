<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $fillable = [
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

    public function environments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProjectEnvironment::class);
    }

    public function clientRepositories(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ClientRepository::class);
    }
}
