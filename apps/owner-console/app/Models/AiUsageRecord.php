<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageRecord extends Model
{
    protected $fillable = [
        'ai_provider_config_id', 'project_id', 'profile',
        'input_tokens', 'output_tokens', 'estimated_cost', 'currency',
    ];

    protected function casts(): array
    {
        return ['input_tokens' => 'integer', 'output_tokens' => 'integer', 'estimated_cost' => 'float'];
    }
}
