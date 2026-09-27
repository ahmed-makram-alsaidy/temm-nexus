<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationAnalysisItem extends Model
{
    public const COMPATIBILITY = [
        'DIRECT', 'SUPPORTED_WITH_TRANSFORM', 'APPLICATION_CONVERSION_REQUIRED',
        'EXTERNAL_INTEGRATION', 'NEEDS_REVIEW', 'BLOCKED', 'NOT_APPLICABLE',
    ];

    protected $fillable = ['migration_analysis_id', 'kind', 'schema_name', 'name', 'attributes', 'compatibility', 'risks'];

    protected function casts(): array
    {
        return ['attributes' => 'array', 'risks' => 'array'];
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(MigrationAnalysis::class, 'migration_analysis_id');
    }
}
