<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InfrastructureEvent extends Model
{
    protected $fillable = ['severity', 'source', 'message', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
