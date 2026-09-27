<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupRun extends Model
{
    protected $fillable = [
        'project_id', 'backup_policy_id', 'environment_id', 'type', 'trigger',
        'started_at', 'finished_at', 'size_bytes', 'checksum', 'destination',
        'status', 'error', 'meta',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'size_bytes' => 'integer', 'meta' => 'array'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(BackupPolicy::class, 'backup_policy_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
