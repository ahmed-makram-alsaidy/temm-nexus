<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'auditable_type', 'auditable_id', 'event', 'actor_id', 'changes', 'ip',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }
}
