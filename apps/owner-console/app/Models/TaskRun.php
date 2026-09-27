<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'task_id', 'request_id', 'status', 'duration_ms', 'output', 'error',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function task(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }
}
