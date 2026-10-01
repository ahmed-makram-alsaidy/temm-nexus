<?php

namespace App\Services\Ai;

use App\Services\Access\Capability;

/**
 * The three context scopes Nexus AI may operate in (0.4.0 §16).
 *
 * Scope is bound to the ROUTE the panel was opened from, never inferred from
 * conversation history, and never silently widened. Crossing a boundary is an
 * explicit user action.
 */
enum Scope: string
{
    case PLATFORM = 'platform';
    case WORKSPACE = 'workspace';
    case PROJECT = 'project';

    public function label(): string
    {
        return match ($this) {
            self::PLATFORM => 'Platform',
            self::WORKSPACE => 'Workspace',
            self::PROJECT => 'Project',
        };
    }

    /** The capability required to open the assistant at this scope. */
    public function openCapability(): string
    {
        return match ($this) {
            self::PLATFORM => Capability::AI_PLATFORM,
            self::WORKSPACE => Capability::AI_WORKSPACE,
            self::PROJECT => Capability::AI_PROJECT,
        };
    }

    /**
     * Does a tool declared at `$toolScope` belong in a conversation opened at
     * THIS scope?
     *
     * A narrower scope may use tools declared at its own level or wider ones
     * whose data is legitimately global (e.g. connector capabilities). It may
     * NEVER use a tool declared for a different branch of the tree — asking
     * about project A must not let the assistant read project B.
     */
    public function admits(self $toolScope): bool
    {
        return match ($this) {
            self::PLATFORM => $toolScope === self::PLATFORM,
            self::WORKSPACE => in_array($toolScope, [self::PLATFORM, self::WORKSPACE], true),
            self::PROJECT => in_array($toolScope, [self::PLATFORM, self::PROJECT], true),
        };
    }
}
