<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectWebhook extends Model
{
    protected $fillable = [
        'project_id', 'name', 'url', 'events', 'enabled',
        'signing_secret', 'max_attempts', 'timeout_s',
    ];

    protected function casts(): array
    {
        return ['events' => 'array', 'enabled' => 'boolean', 'signing_secret' => 'encrypted'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_id')->orderByDesc('id');
    }

    public function project(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
