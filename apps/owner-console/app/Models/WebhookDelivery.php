<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    protected $fillable = [
        'webhook_id', 'request_id', 'event', 'status', 'http_code',
        'duration_ms', 'attempts', 'payload', 'response', 'next_retry_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'next_retry_at' => 'datetime'];
    }

    public function webhook(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProjectWebhook::class, 'webhook_id');
    }
}
