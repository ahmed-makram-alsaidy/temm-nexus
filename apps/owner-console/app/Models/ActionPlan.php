<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 0.4.0 Phase J — a persistent, permissioned AI action plan.
 *
 * Immutability contract: everything the approval is bound to (action,
 * arguments, scope, fingerprint, expiry) is written ONCE at propose time and
 * never edited. Execution reads the row — never client input — so an
 * approval cannot be replayed against different arguments, and a provider
 * cannot alter a proposal after the fact.
 *
 * Statuses:
 *   pending            awaiting a human decision (or its expiry)
 *   approved           claimed for execution (atomic transition; idempotency gate)
 *   verified           applied AND verified
 *   verification_failed applied but verification could not confirm — shown honestly
 *   failed             execution threw; safe error recorded
 *   rejected           a human declined
 *   expired            the approval window passed
 *   stale              the state fingerprint changed; refused
 */
class ActionPlan extends Model
{
    use HasUuids;

    protected $table = 'ai_action_plans';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_VERIFICATION_FAILED = 'verification_failed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_STALE = 'stale';

    /** How long a proposal stays approvable. */
    public const TTL_MINUTES = 15;

    protected $fillable = [
        'user_id', 'approved_by', 'action', 'scope', 'workspace_id', 'project_id',
        'arguments', 'intent', 'affected', 'risk', 'fingerprint', 'verification',
        'status', 'expires_at', 'approved_at', 'executed_at', 'result', 'attempts',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'affected' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** The plan card the UI renders — safe fields only. */
    public function card(): array
    {
        return [
            'plan_id' => $this->id,
            'action' => $this->action,
            'intent' => $this->intent,
            'affected' => $this->affected,
            'risk' => $this->risk,
            'expected' => $this->verification,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'status' => $this->status,
            'result' => $this->result,
        ];
    }
}
