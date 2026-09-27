<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RealtimeEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'project_id', 'channel', 'event', 'payload', 'request_id', 'verified',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'verified' => 'boolean', 'created_at' => 'datetime'];
    }
}
