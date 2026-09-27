<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalAccountConnection extends Model
{
    protected $fillable = [
        'provider', 'display_name', 'owner_user_id', 'secret_encrypted', 'secret_ref',
        'status', 'last_result', 'last_verified_at', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'last_verified_at' => 'datetime', 'secret_encrypted' => 'encrypted'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
