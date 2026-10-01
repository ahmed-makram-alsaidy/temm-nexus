<?php

namespace App\Services\Ai;

use App\Models\Project;
use App\Models\Workspace;
use App\Services\Access\Access;

/**
 * A resolved AI context: WHO is asking, at WHICH scope, about WHICH object.
 *
 * Every tool call carries one of these, and the dispatcher re-checks the acting
 * user's capability at execution time against the object in this context — not
 * against whatever was true when the conversation started (0.4.0 §17).
 *
 * The context is a value object: it never holds credentials, connection
 * strings, secret values, or customer rows, so it is safe to include in a
 * prompt or an audit entry.
 */
final class AiContext
{
    public function __construct(
        public readonly Scope $scope,
        public readonly Access $access,
        public readonly ?Workspace $workspace = null,
        public readonly ?Project $project = null,
        /** The user-visible page the assistant was opened from, for Inspect Mode. */
        public readonly ?string $pageKey = null,
    ) {}

    public static function platform(Access $access, ?string $pageKey = null): self
    {
        return new self(Scope::PLATFORM, $access, null, null, $pageKey);
    }

    public static function workspace(Access $access, Workspace $workspace, ?string $pageKey = null): self
    {
        return new self(Scope::WORKSPACE, $access, $workspace, null, $pageKey);
    }

    public static function project(Access $access, Project $project, ?string $pageKey = null): self
    {
        return new self(Scope::PROJECT, $access, $project->workspace, $project, $pageKey);
    }

    /**
     * Derive the context from the current request. This is the ONLY legitimate
     * way the assistant learns its scope: the route's bound Project/Workspace.
     */
    public static function fromRequest(?string $pageKey = null): self
    {
        $access = Access::for(auth()->user());
        $route = request()->route();

        $record = $route?->parameter('record');
        if ($record instanceof Project) {
            return self::project($access, $record, $pageKey);
        }

        $workspace = $route?->parameter('workspace');
        if ($workspace instanceof Workspace) {
            return self::workspace($access, $workspace, $pageKey);
        }

        if (is_string($workspace) && $workspace !== '') {
            $found = Workspace::query()->where('slug', $workspace)->first();
            if ($found) {
                return self::workspace($access, $found, $pageKey);
            }
        }

        return self::platform($access, $pageKey);
    }

    /** Capability required to open the assistant here. */
    public function openCapability(): string
    {
        return $this->scope->openCapability();
    }

    /** May the acting user open the assistant at this scope at all? */
    public function isOpenable(): bool
    {
        return match ($this->scope) {
            Scope::PLATFORM => $this->access->allows($this->openCapability(), 'platform'),
            Scope::WORKSPACE => $this->workspace !== null
                && $this->access->canReachWorkspace($this->workspace)
                && $this->access->allows($this->openCapability(), 'workspace', $this->workspace),
            Scope::PROJECT => $this->project !== null
                && $this->access->canReachProject($this->project)
                && $this->access->allows($this->openCapability(), 'project', null, $this->project),
        };
    }

    /** Capability check at this context's own scope. */
    public function allows(string $capability): bool
    {
        return match ($this->scope) {
            Scope::PLATFORM => $this->access->allows($capability, 'platform'),
            Scope::WORKSPACE => $this->access->allows($capability, 'workspace', $this->workspace),
            Scope::PROJECT => $this->access->allows($capability, 'project', null, $this->project),
        };
    }

    /**
     * Resolve the project a tool should act on.
     *
     * A project-scoped tool may ONLY act on the project in this context. There
     * is deliberately no way for a tool argument to name a different project —
     * that is the primary prompt-injection defence for the AI layer.
     */
    public function projectTarget(): ?Project
    {
        return $this->project;
    }

    /**
     * The scope label shown in the assistant header, e.g.
     * "Project — Wasla". Never null, so the user always sees their scope.
     */
    public function label(): string
    {
        return match ($this->scope) {
            Scope::PLATFORM => 'Platform',
            Scope::WORKSPACE => 'Workspace — '.($this->workspace?->name ?? 'unknown'),
            Scope::PROJECT => 'Project — '.($this->project?->name ?? 'unknown'),
        };
    }

    /** Structured, secret-free description for audit entries. */
    public function auditPayload(): array
    {
        return [
            'scope' => $this->scope->value,
            'workspace_id' => $this->workspace?->getKey(),
            'project_id' => $this->project?->getKey(),
            'user_id' => $this->access->user()->getKey(),
            'page_key' => $this->pageKey,
            'platform_role' => $this->access->platformRole(),
            'workspace_role' => $this->workspace
                ? $this->access->workspaceRole($this->workspace)
                : null,
            'project_role' => $this->project
                ? $this->access->projectRole($this->project)
                : null,
        ];
    }
}
