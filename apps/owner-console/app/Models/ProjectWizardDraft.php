<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 0.6.0 Phase E (§E3) — server-side New Project wizard state.
 *
 * One draft per user, persisted from the FIRST meaningful step so the
 * wizard's "stop and finish later" promise is true. Only SAFE values are
 * stored: names, ids, environment, connector key, non-secret connection
 * fields. Secrets follow the existing write-once vault handling and are
 * never written here (the wizard strips them before saving).
 */
class ProjectWizardDraft extends Model
{
    protected $fillable = [
        'user_id', 'step', 'state', 'project_id', 'source_id',
        'analysis_id', 'plan_id', 'test_result',
    ];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'test_result' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The draft for the acting user, or null. */
    public static function forCurrentUser(): ?self
    {
        $userId = auth()->id();

        return $userId === null ? null : static::query()->where('user_id', $userId)->first();
    }

    /** Create-or-update the acting user's draft. */
    public static function store(int $step, array $state, array $ids = [], ?array $testResult = null): self
    {
        $userId = auth()->id();
        if ($userId === null) {
            // No authenticated user → nothing to persist against; the wizard
            // still works, it just cannot promise resume.
            return new self;
        }

        return static::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'step' => $step,
                'state' => $state,
                'project_id' => $ids['project_id'] ?? null,
                'source_id' => $ids['source_id'] ?? null,
                'analysis_id' => $ids['analysis_id'] ?? null,
                'plan_id' => $ids['plan_id'] ?? null,
                'test_result' => $testResult,
            ]
        );
    }
}
