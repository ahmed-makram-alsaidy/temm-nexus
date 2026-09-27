<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectSecret extends Model
{
    public const CATEGORIES = [
        'database', 'redis', 'storage', 'oauth', 'webhook', 'whatsapp',
        'ecommerce', 'payment', 'partner', 'application', 'custom',
    ];

    protected $fillable = [
        'project_id', 'name', 'value', 'description', 'env', 'last_rotated_at',
        // Phase 24 vault extensions.
        'environment_id', 'category', 'version', 'status', 'created_by', 'updated_by', 'rotation_meta',
    ];

    protected function casts(): array
    {
        // NOTE: reversible encryption; key = APP_KEY (server env, never in DB).
        // Documented in 20M: rotation + restricted host access are the controls.
        return [
            'value' => 'encrypted',
            'last_rotated_at' => 'datetime',
            'version' => 'integer',
            'rotation_meta' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironment::class);
    }
}
