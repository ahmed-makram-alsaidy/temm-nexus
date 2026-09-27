<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModelProfile extends Model
{
    public const PURPOSES = ['planner', 'builder', 'validator'];

    protected $fillable = ['ai_provider_config_id', 'name', 'model', 'max_output_tokens'];

    protected function casts(): array
    {
        return ['max_output_tokens' => 'integer'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProviderConfig::class, 'ai_provider_config_id');
    }
}
