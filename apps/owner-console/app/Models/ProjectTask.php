<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectTask extends Model
{
    protected $fillable = [
        'project_id', 'name', 'cron', 'target_type', 'target_ref', 'enabled',
        'last_run_at', 'next_run_at', 'last_status', 'last_duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'last_run_at' => 'datetime', 'next_run_at' => 'datetime',
        ];
    }

    public function project(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
