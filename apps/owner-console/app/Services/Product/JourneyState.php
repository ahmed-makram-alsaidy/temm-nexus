<?php

namespace App\Services\Product;

/**
 * 0.4.0 §6/§10 — the six product states every journey stage and readiness gate
 * resolves to.
 *
 * Each state carries an icon and a label so the UI never communicates status by
 * colour alone (§34). `isBlocking()` drives the "why can I not proceed" copy
 * that §10 requires.
 */
enum JourneyState: string
{
    case NOT_STARTED = 'not_started';
    case IN_PROGRESS = 'in_progress';
    case READY = 'ready';
    case NEEDS_ATTENTION = 'needs_attention';
    case BLOCKED = 'blocked';
    case COMPLETE = 'complete';

    public function label(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'Not started',
            self::IN_PROGRESS => 'In progress',
            self::READY => 'Ready',
            self::NEEDS_ATTENTION => 'Needs attention',
            self::BLOCKED => 'Blocked',
            self::COMPLETE => 'Complete',
        };
    }

    /**
     * Heroicon name — always paired with the label.
     *
     * NOTE: these must exist in blade-heroicons' `o-*` set. `JourneyStateTest`
     * asserts every name resolves, because an unknown icon throws at RENDER
     * time and turns the whole page into a 500 — which is exactly how
     * `heroicon-o-circle` (not a real Heroicon) was caught.
     */
    public function icon(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'heroicon-o-minus-circle',
            self::IN_PROGRESS => 'heroicon-o-arrow-path',
            self::READY => 'heroicon-o-check-circle',
            self::NEEDS_ATTENTION => 'heroicon-o-exclamation-triangle',
            self::BLOCKED => 'heroicon-o-no-symbol',
            self::COMPLETE => 'heroicon-o-check-badge',
        };
    }

    /** Maps to a `.nx-status--*` modifier. */
    public function tone(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'neutral',
            self::IN_PROGRESS => 'info',
            self::READY => 'success',
            self::NEEDS_ATTENTION => 'warning',
            self::BLOCKED => 'danger',
            self::COMPLETE => 'success',
        };
    }

    /** A stage in this state stops the user from moving on. */
    public function isBlocking(): bool
    {
        return $this === self::BLOCKED;
    }

    /** Counts as "finished" for progress arithmetic. */
    public function isSettled(): bool
    {
        return in_array($this, [self::COMPLETE, self::READY], true);
    }

    /**
     * Translate a raw internal status token into product language.
     *
     * Used at the last moment, when rendering. Internal tokens stay internal:
     * they remain visible only under Advanced details (§14).
     */
    public static function fromRaw(?string $raw): self
    {
        return match (strtolower((string) $raw)) {
            '', 'not_started', 'pending', 'draft', 'queued' => self::NOT_STARTED,
            'running', 'in_progress', 'started', 'streaming', 'applying', 'active' => self::IN_PROGRESS,
            'ready', 'validated', 'approved', 'ok', 'completed', 'complete', 'succeeded', 'success' => self::COMPLETE,
            'paused', 'degraded', 'stale', 'lagging', 'warning', 'partial' => self::NEEDS_ATTENTION,
            'failed', 'error', 'blocked', 'unhealthy', 'aborted', 'tampered' => self::BLOCKED,
            default => self::NOT_STARTED,
        };
    }
}
