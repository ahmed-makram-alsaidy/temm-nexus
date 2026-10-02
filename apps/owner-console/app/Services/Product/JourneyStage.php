<?php

namespace App\Services\Product;

/**
 * 0.4.0 §6 — the migration journey, and the product language for its states.
 *
 * The seven stages are the spine of the project scope. Every stage resolves to
 * exactly one `JourneyState`, and every state has an icon AND a label so status
 * never depends on colour alone (§34).
 *
 * Raw internal tokens (`draft`, `paused`, `pending`, `unknown`) are NEVER the
 * primary label. `rawLabel()` exposes them only for the Advanced disclosure.
 */
enum JourneyStage: string
{
    case CONNECT = 'connect';
    case ANALYZE = 'analyze';
    case PLAN = 'plan';
    case MIGRATE = 'migrate';
    case SYNC = 'sync';
    case VALIDATE = 'validate';
    case CUTOVER = 'cutover';

    /** Ordered stages — the journey reads left to right. */
    public static function sequence(): array
    {
        return [
            self::CONNECT,
            self::ANALYZE,
            self::PLAN,
            self::MIGRATE,
            self::SYNC,
            self::VALIDATE,
            self::CUTOVER,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::CONNECT => 'Connect',
            self::ANALYZE => 'Analyze',
            self::PLAN => 'Plan',
            self::MIGRATE => 'Migrate',
            self::SYNC => 'Sync',
            self::VALIDATE => 'Validate',
            self::CUTOVER => 'Cutover',
        };
    }

    /** One line explaining what the stage achieves, for the stepper detail. */
    public function description(): string
    {
        return match ($this) {
            self::CONNECT => 'Link the source you are migrating from.',
            self::ANALYZE => 'Inventory tables, rows, and compatibility.',
            self::PLAN => 'Review the plan and the changes it will make.',
            self::MIGRATE => 'Move the initial dataset.',
            self::SYNC => 'Keep changes flowing while you prepare to switch.',
            self::VALIDATE => 'Confirm the data matches.',
            self::CUTOVER => 'Switch production over, with a rollback ready.',
        };
    }

    public function position(): int
    {
        return array_search($this, self::sequence(), true) ?: 0;
    }
}
