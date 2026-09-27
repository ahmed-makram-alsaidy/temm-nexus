<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FunctionInvocation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'function_id', 'version', 'request_id', 'actor', 'status',
        'duration_ms', 'request', 'response', 'error',
    ];

    protected function casts(): array
    {
        return ['request' => 'array', 'response' => 'array', 'created_at' => 'datetime'];
    }

    public function function(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProjectFunction::class, 'function_id');
    }
}
