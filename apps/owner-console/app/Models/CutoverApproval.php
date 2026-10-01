<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 34D — one explicit human approval/rejection for a cutover gate.
 * Production-affecting steps require a matching approval record before the
 * plan can progress — nothing auto-approves.
 */
class CutoverApproval extends Model
{
    protected $fillable = [
        'cutover_plan_id', 'gate', 'decision', 'note', 'approved_by', 'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];
}
