<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 34F — one audited cutover event (who/what/when/plan/result).
 */
class CutoverEvent extends Model
{
    protected $fillable = [
        'cutover_plan_id', 'event', 'details', 'actor_id', 'request_id',
    ];

    protected $casts = [
        'details' => 'array',
    ];
}
