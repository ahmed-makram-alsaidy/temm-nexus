<?php

namespace App\Services\Access;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Collection;

/**
 * The single authorisation entry point for 0.4.0.
 *
 * DESIGN RULES (enforced here, not in the UI)
 * -------------------------------------------
 *  1. FAIL CLOSED. An unknown role, a missing membership, a missing table, or
 *     an unresolvable scope grants nothing. There is no implicit "allow".
 *  2. EXECUTION-TIME CHECK. Every call re-reads membership from the database.
 *     Nothing is cached across requests, so revoking access takes effect on the
 *     user's very next action — including inside an AI tool call that was
 *     planned while they still had access.
 *  3. ROLES ARE BUNDLES. We expand a role into capabilities and compare
 *     capabilities. Role *names* are never compared to authorise.
 *  4. SCOPE IS EXPLICIT. A capability check names the scope it is asked about
 *     (platform / workspace / project). A project-scoped check can never be
 *     satisfied by an unrelated workspace grant.
 *  5. THE AI INHERITS THIS. Nexus Copilot tools call `Access` with the acting
 *     user, so the AI can never become a permission bypass.
 */
final class Access
{
    /** Legacy `cp_role` values from Phase 20V that still grant panel entry. */
    private const LEGACY_ROLES = ['owner', 'admin', 'developer', 'observer'];

    public function __construct(
        private readonly User $user,
    ) {}

    public static function for(?User $user): self
    {
        // An anonymous principal is represented by a null-id sentinel user so
        // every downstream check fails closed without null-guards everywhere.
        return new self($user ?? new User);
    }

    public function user(): User
    {
        return $this->user;
    }

    // ─────────────────────────────────────────────────────────────────
    // Identity
    // ─────────────────────────────────────────────────────────────────

    public function isAuthenticated(): bool
    {
        return $this->user->exists && $this->user->getKey() !== null;
    }

    /**
     * Effective platform role.
     *
     * `users.platform_role` wins when set. Otherwise a legacy `cp_role` is
     * translated once, or a legacy `is_admin` flag implies Platform Owner.
     * Returns null when the user holds no platform-wide authority — which is
     * the normal case for a client contact.
     */
    public function platformRole(): ?string
    {
        if (! $this->isAuthenticated()) {
            return null;
        }

        $role = $this->user->platform_role;
        if (Roles::existsAt('platform', $role)) {
            return $role;
        }

        // Legacy bridge — read-only translation, nothing is written here.
        if ($this->user->cp_role === 'owner') {
            return Roles::PLATFORM_OWNER;
        }

        if ($this->user->is_admin) {
            return Roles::PLATFORM_OWNER;
        }

        return null;
    }

    public function isPlatformOwner(): bool
    {
        return $this->platformRole() === Roles::PLATFORM_OWNER;
    }

    /** True when the user holds ANY platform-wide role. */
    public function hasPlatformAccess(): bool
    {
        return $this->platformRole() !== null;
    }

    /** Capabilities granted by the platform role alone. */
    private function platformCapabilities(): array
    {
        return Roles::capabilities('platform', $this->platformRole());
    }

    // ─────────────────────────────────────────────────────────────────
    // Capability checks
    // ─────────────────────────────────────────────────────────────────

    /**
     * Capability check at an explicit scope. This is the method every surface
     * and every AI tool must call.
     *
     * @param  string  $capability  a Capability::* constant
     * @param  Workspace|int|string|null  $workspace  required for workspace/project scope
     * @param  Project|int|string|null  $project  required for project scope
     * @param  string  $scope  'platform' | 'workspace' | 'project'
     */
    public function allows(string $capability, string $scope = 'platform', Workspace|int|string|null $workspace = null, Project|int|string|null $project = null): bool
    {
        if (! Capability::exists($capability)) {
            // A typo in a capability name must never widen access.
            return false;
        }

        if (! $this->isAuthenticated()) {
            return false;
        }

        // Platform-wide grant short-circuits — but only for a REAL platform
        // role, and only after the capability name was validated above.
        foreach ($this->platformCapabilities() as $granted) {
            if ($granted === '*' || $granted === $capability) {
                return true;
            }
        }

        return match ($scope) {
            'platform' => false,
            'workspace' => $this->allowsInWorkspace($capability, $workspace),
            'project' => $this->allowsInProject($capability, $project, $workspace),
            default => false,
        };
    }

    private function allowsInWorkspace(string $capability, Workspace|int|string|null $workspace): bool
    {
        $workspaceId = $this->resolveWorkspaceId($workspace);
        if ($workspaceId === null) {
            return false;
        }

        foreach ($this->workspaceCapabilities($workspaceId) as $granted) {
            if ($granted === '*' || $granted === $capability) {
                return true;
            }
        }

        return false;
    }

    private function allowsInProject(string $capability, Project|int|string|null $project, Workspace|int|string|null $workspace): bool
    {
        $projectId = $this->resolveProjectId($project);
        if ($projectId === null) {
            return false;
        }

        // 1. An explicit project membership is the narrowest and most specific
        //    grant, and is always additive to any workspace grant.
        $projectRole = $this->projectRole($projectId);
        if ($projectRole !== null) {
            foreach (Roles::capabilities('project', $projectRole) as $granted) {
                if ($granted === '*' || $granted === $capability) {
                    return true;
                }
            }
        }

        // 2. Otherwise fall back to the owning workspace. Pass the project's
        //    OWN workspace, never a caller-supplied one, so a wrong workspace
        //    argument can never widen the check.
        $ownerWorkspaceId = $this->projectWorkspaceId($projectId);
        if ($ownerWorkspaceId === null) {
            return false;
        }

        if ($workspace !== null && $this->resolveWorkspaceId($workspace) !== $ownerWorkspaceId) {
            // Caller asserted a workspace that does not own this project.
            return false;
        }

        foreach ($this->workspaceCapabilities($ownerWorkspaceId) as $granted) {
            if ($granted === '*' || $granted === $capability) {
                return true;
            }
        }

        return false;
    }

    /** Abort with 403 unless the capability is held. */
    public function authorize(string $capability, string $scope = 'platform', Workspace|int|string|null $workspace = null, Project|int|string|null $project = null): void
    {
        abort_unless(
            $this->allows($capability, $scope, $workspace, $project),
            403,
            'Missing capability: '.$capability,
        );
    }

    /** Non-aborting check that also explains itself (used by AI tool results). */
    public function explain(string $capability, string $scope = 'platform', Workspace|int|string|null $workspace = null, Project|int|string|null $project = null): array
    {
        return [
            'capability' => $capability,
            'scope' => $scope,
            'allowed' => $this->allows($capability, $scope, $workspace, $project),
            'platform_role' => $this->platformRole(),
            'workspace_role' => $scope !== 'platform' ? $this->workspaceRole($workspace ?? $project) : null,
            'project_role' => $scope === 'project' ? $this->projectRole($project) : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Role resolution
    // ─────────────────────────────────────────────────────────────────

    private function workspaceCapabilities(int $workspaceId): array
    {
        $membership = $this->membership($workspaceId);

        return $membership ? Roles::capabilities('workspace', $membership->role) : [];
    }

    private function membership(int $workspaceId): ?WorkspaceMember
    {
        try {
            return WorkspaceMember::query()
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $this->user->getKey())
                ->where('status', 'active')
                ->first();
        } catch (\Throwable) {
            // Table missing (pre-migration) => deny.
            return null;
        }
    }

    public function workspaceRole(Workspace|int|string|Project|null $workspace): ?string
    {
        $id = $this->resolveWorkspaceId($workspace);
        if ($id === null) {
            return null;
        }

        return $this->membership($id)?->role;
    }

    public function projectRole(Project|int|string|null $project): ?string
    {
        $projectId = $this->resolveProjectId($project);
        if ($projectId === null) {
            return null;
        }

        try {
            $membership = ProjectMember::query()
                ->where('project_id', $projectId)
                ->where('user_id', $this->user->getKey())
                ->where('status', 'active')
                ->first();
        } catch (\Throwable) {
            return null;
        }

        return $membership?->role;
    }

    // ─────────────────────────────────────────────────────────────────
    // Reachable sets — the ONLY way UI and AI may enumerate objects
    // ─────────────────────────────────────────────────────────────────

    /**
     * Workspaces this user may see.
     *
     * A platform role sees all. Otherwise only workspaces where an ACTIVE
     * membership exists. Never derived from navigation or a request parameter.
     *
     * @return Collection<int, Workspace>
     */
    public function accessibleWorkspaces(): Collection
    {
        if (! $this->isAuthenticated()) {
            return collect();
        }

        if ($this->hasPlatformAccess()) {
            return Workspace::query()->orderBy('name')->get();
        }

        try {
            $ids = WorkspaceMember::query()
                ->where('user_id', $this->user->getKey())
                ->where('status', 'active')
                ->pluck('workspace_id');
        } catch (\Throwable) {
            return collect();
        }

        if ($ids->isEmpty()) {
            return collect();
        }

        return Workspace::query()->whereIn('id', $ids)->orderBy('name')->get();
    }

    /** @return list<int> */
    public function accessibleWorkspaceIds(): array
    {
        return $this->accessibleWorkspaces()->pluck('id')->all();
    }

    /**
     * Projects this user may see.
     *
     * Narrower than `accessibleWorkspaces()` on purpose: a project membership
     * adds exactly one project even when the user has no workspace membership.
     *
     * @return Collection<int, Project>
     */
    public function accessibleProjects(): Collection
    {
        if (! $this->isAuthenticated()) {
            return collect();
        }

        if ($this->hasPlatformAccess()) {
            return Project::query()->orderBy('name')->get();
        }

        $workspaceIds = $this->accessibleWorkspaceIds();

        try {
            $directProjectIds = ProjectMember::query()
                ->where('user_id', $this->user->getKey())
                ->where('status', 'active')
                ->pluck('project_id')
                ->all();
        } catch (\Throwable) {
            $directProjectIds = [];
        }

        if ($workspaceIds === [] && $directProjectIds === []) {
            return collect();
        }

        return Project::query()
            ->where(function ($q) use ($workspaceIds, $directProjectIds) {
                if ($workspaceIds !== []) {
                    $q->whereIn('workspace_id', $workspaceIds);
                }
                if ($directProjectIds !== []) {
                    $q->orWhereIn('id', $directProjectIds);
                }
            })
            ->orderBy('name')
            ->get();
    }

    /** @return list<int> */
    public function accessibleProjectIds(): array
    {
        return $this->accessibleProjects()->pluck('id')->all();
    }

    /** May the user open a specific project at all? */
    public function canReachProject(Project|int|string|null $project): bool
    {
        $projectId = $this->resolveProjectId($project);

        return $projectId !== null && in_array($projectId, $this->accessibleProjectIds(), true);
    }

    /** May the user open a specific workspace at all? */
    public function canReachWorkspace(Workspace|int|string|null $workspace): bool
    {
        $workspaceId = $this->resolveWorkspaceId($workspace);

        return $workspaceId !== null && in_array($workspaceId, $this->accessibleWorkspaceIds(), true);
    }

    /**
     * Enforce that a project is reachable, aborting 404 (not 403) so the
     * existence of another tenant's project is never confirmed.
     */
    public function authorizeProjectReach(Project|int|string|null $project): void
    {
        abort_unless($this->canReachProject($project), 404);
    }

    /** Enforce workspace reachability, also as 404. */
    public function authorizeWorkspaceReach(Workspace|int|string|null $workspace): void
    {
        abort_unless($this->canReachWorkspace($workspace), 404);
    }

    // ─────────────────────────────────────────────────────────────────
    // Resolution helpers
    // ─────────────────────────────────────────────────────────────────

    private function resolveWorkspaceId(Workspace|int|string|Project|null $workspace): ?int
    {
        if ($workspace instanceof Workspace) {
            return $workspace->getKey();
        }

        if ($workspace instanceof Project) {
            return $this->projectWorkspaceId($workspace->getKey());
        }

        if (is_int($workspace)) {
            return $workspace;
        }

        if (is_string($workspace) && $workspace !== '') {
            // A string is a slug for workspace routes, or a numeric id.
            if (ctype_digit($workspace)) {
                return (int) $workspace;
            }

            try {
                return Workspace::query()->where('slug', $workspace)->value('id');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function resolveProjectId(Project|int|string|null $project): ?int
    {
        if ($project instanceof Project) {
            return $project->getKey();
        }

        if (is_int($project)) {
            return $project;
        }

        if (is_string($project) && $project !== '') {
            if (ctype_digit($project)) {
                return (int) $project;
            }

            try {
                return Project::query()->where('slug', $project)->value('id');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function projectWorkspaceId(int $projectId): ?int
    {
        try {
            $value = Project::query()->whereKey($projectId)->value('workspace_id');
        } catch (\Throwable) {
            return null;
        }

        return $value === null ? null : (int) $value;
    }
}
