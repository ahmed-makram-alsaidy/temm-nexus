<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiProviderConfig extends Model
{
    public const PROVIDERS = ['openai', 'gemini', 'anthropic', 'openrouter', 'openai_compatible', 'fake'];

    protected $fillable = [
        'provider', 'display_name', 'base_url', 'model', 'secret_encrypted', 'secret_ref', 'enabled',
        'timeout_seconds', 'max_output_tokens', 'pricing', 'project_id', 'status', 'custom_headers',
    ];

    protected function casts(): array
    {
        return [
            'last_tested_at' => 'datetime','enabled' => 'boolean', 'pricing' => 'array', 'max_output_tokens' => 'integer', 'timeout_seconds' => 'integer', 'secret_encrypted' => 'encrypted', 'custom_headers' => 'encrypted:array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(AiModelProfile::class);
    }
}
