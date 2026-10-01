<?php

namespace App\Filament\Support;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\Access;
use App\Services\Access\Capability;
use Illuminate\Database\Eloquent\Builder;

/**
 * Request-scoped convenience layer over `App\Services\Access\Access` for the
 * Filament chrome.
 *
 * WHY THIS EXISTS
 * The navigation builder and page headers ask "can this user see X?" many times
 * per render. `Access` deliberately re-reads membership on every call so that
 * revocation is immediate — correct for a security decision, wasteful for
 * deciding whether to draw a nav link. This class memoises the *reachable sets*
 * for the lifetime of ONE request only, so a revocation still takes effect on
 * the next request, while a single page render stays cheap.
 *
 * It is NOT a cache. There is no TTL, no cross-request store, and no fallback:
 * if the authenticated user changes, `current()` re-resolves.
 */
final class PlatformAccess
{
    private static ?self $instance = null;

    private static ?int $boundUserId = null;

    /** Request identity this instance was memoised for. */
    private static ?string $boundRequestKey = null;

    private ?Access $access = null;

    /** @var list<int>|null */
    private ?array $workspaceIds = null;

    /** @var list<int>|null */
    private ?array $projectIds = null;

    private function __construct() {}

    public static function current(): self
    {
        $userId = auth()->id();

        // Re-bind whenever the acting user OR the incoming request changes.
        //
        // The request key matters in long-lived processes (queue workers, and
        // the test client, which issues several requests from one PHP process).
        // Without it, a memoised reachable set computed during request N would
        // answer a capability question in request N+1 — i.e. an authorisation
        // decision made against stale data. Keying on the request object keeps
        // the memoisation to exactly one request, which is the whole contract.
        $requestKey = $userId.'|'.spl_object_id(request());

        if (self::$instance === null
            || self::$boundUserId !== $userId
            || self::$boundRequestKey !== $requestKey) {
            self::$instance = new self;
            self::$boundUserId = $userId;
            self::$boundRequestKey = $requestKey;
        }

        return self::$instance;
    }

    /** Forget memoised sets — call after changing memberships mid-request. */
    public static function flush(): void
    {
        self::$instance = null;
        self::$boundUserId = null;
        self::$boundRequestKey = null;
    }

    public function access(): Access
    {
        return $this->access ??= Access::for(auth()->user());
    }

    public function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    // ── Capability checks ──────────────────────────────────────────────

    /** Platform-scope capability (cross-workspace). */
    public function allowsPlatform(string $capability): bool
    {
        return $this->access()->allows($capability, 'platform');
    }

    /** Workspace-scope capability. */
    public function allowsWorkspace(string $capability, Workspace|int|string|null $workspace): bool
    {
        return $this->access()->allows($capability, 'workspace', $workspace);
    }

    /** Project-scope capability. */
    public function allowsProject(string $capability, Project|int|string|null $project): bool
    {
        return $this->access()->allows($capability, 'project', null, $project);
    }

    /** Capability at whichever scope the current route implies. */
    public function allowsHere(string $capability): bool
    {
        $project = ControlPlaneChrome::currentProject();

        if ($project) {
            return $this->allowsProject($capability, $project);
        }

        return $this->allowsPlatform($capability);
    }

    // ── Reachable sets (memoised per request) ──────────────────────────

    /** @return list<int> */
    public function workspaceIds(): array
    {
        return $this->workspaceIds ??= $this->access()->accessibleWorkspaceIds();
    }

    /** @return list<int> */
    public function projectIds(): array
    {
        return $this->projectIds ??= $this->access()->accessibleProjectIds();
    }

    public function canReachProject(Project|int|string|null $project): bool
    {
        $id = $project instanceof Project ? $project->getKey() : $project;

        return $id !== null && in_array((int) $id, $this->projectIds(), true);
    }

    public function canReachWorkspace(Workspace|int|string|null $workspace): bool
    {
        $id = $workspace instanceof Workspace ? $workspace->getKey() : $workspace;

        return $id !== null && in_array((int) $id, $this->workspaceIds(), true);
    }

    /** Projects the user may see, as a query — never an unbounded collection. */
    public function projectsQuery(): Builder
    {
        $query = Project::query();

        if ($this->access()->hasPlatformAccess()) {
            return $query;
        }

        return $query->whereIn('id', $this->projectIds());
    }

    /** True when the user can create a project somewhere. */
    public function canCreateProject(): bool
    {
        if ($this->allowsPlatform(Capability::PROJECTS_CREATE)) {
            return true;
        }

        foreach ($this->workspaceIds() as $workspaceId) {
            if ($this->allowsWorkspace(Capability::PROJECTS_CREATE, $workspaceId)) {
                return true;
            }
        }

        return false;
    }
}
