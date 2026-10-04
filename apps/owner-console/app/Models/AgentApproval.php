<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A human decision bound to ONE changeset fingerprint of ONE task cut from
 * ONE base revision. Single-use: a successful apply consumes it. Any change
 * to the changeset content or the base revision invalidates it.
 */
class AgentApproval extends Model
{
    use HasUuids;

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'agent_task_id', 'changeset_fingerprint', 'base_revision',
        'approver_id', 'status', 'note', 'consumed_at',
    ];

    protected function casts(): array
    {
        return ['consumed_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class, 'agent_task_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
