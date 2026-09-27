<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectFunction extends Model
{
    protected $fillable = [
        'project_id', 'name', 'slug', 'description', 'type', 'enabled',
        'methods', 'auth_mode', 'timeout_s', 'rate_limit_per_min', 'current_version_id',
    ];

    protected function casts(): array
    {
        return ['methods' => 'array', 'enabled' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FunctionVersion::class, 'function_id')->orderBy('version');
    }

    public function invocations(): HasMany
    {
        return $this->hasMany(FunctionInvocation::class, 'function_id')->orderByDesc('id');
    }

    public function project(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
