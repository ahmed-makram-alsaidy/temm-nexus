<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupPolicy extends Model
{
    protected $fillable = [
        'project_id', 'environment_id', 'destination_id', 'name', 'scope',
        'schedule', 'retention_days', 'encrypted', 'status', 'last_run_at', 'last_restore_test_at',
    ];

    protected function casts(): array
    {
        return ['encrypted' => 'boolean', 'last_run_at' => 'datetime', 'last_restore_test_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'destination_id');
    }
}
