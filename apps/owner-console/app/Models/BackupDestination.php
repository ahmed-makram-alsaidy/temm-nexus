<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupDestination extends Model
{
    public const DRIVERS = ['local', 's3-compatible'];

    protected $fillable = ['project_id', 'name', 'driver', 'config', 'secret_ref', 'status', 'last_error'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
