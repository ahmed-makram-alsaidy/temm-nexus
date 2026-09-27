<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientCallsite extends Model
{
    public const STATUSES = ['DISCOVERED', 'MAPPED', 'CONVERTED', 'REVIEW', 'IGNORED_WITH_REASON'];

    protected $fillable = [
        'client_repository_id', 'file', 'line', 'category', 'target',
        'language', 'confidence', 'status', 'evidence_hash', 'note',
    ];

    public function repository(): BelongsTo
    {
        return $this->belongsTo(ClientRepository::class, 'client_repository_id');
    }
}
