<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationArtifact extends Model
{
    protected $fillable = [
        'project_id', 'migration_run_id', 'migration_analysis_id', 'kind', 'path', 'summary',
    ];

    protected function casts(): array
    {
        return ['summary' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
