<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\NexusAi;
use App\Filament\Support\PlatformAccess;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Access\Capability;
use App\Services\Access\Roles;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * 0.4.0 Phase C — WORKSPACE scope dashboard.
 *
 * The client's home: identity, health, projects, members, activity, and the
 * three primary actions (New Project, Invite Member, Ask Nexus AI).
 *
 * SECURITY
 * The workspace is resolved from the route and then authorised against
 * `Access::accessibleWorkspaces()`. An unreachable workspace returns **404**,
 * not 403, so the existence of another tenant's workspace is never confirmed.
 */
class WorkspaceDetail extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static bool $shouldRegisterNavigation = false;

    /**
     * NOTE ON THE ROUTE PARAMETER NAME
     * Livewire assigns every route parameter whose name matches a public
     * property directly onto that property, BEFORE mount() runs. Naming the
     * parameter `{workspace}` therefore tried to write the raw slug string into
     * this typed `?Workspace $workspace` property and blew up with
     * "Cannot assign string to property ... of type ?App\Models\Workspace".
     * The parameter is `{workspaceSlug}` so the property stays ours to set.
     */
    protected static ?string $slug = 'workspaces/{workspaceSlug}';

    protected string $view = 'filament.pages.workspace-detail';

    public ?Workspace $workspace = null;

    public static function urlFor(Workspace $workspace): string
    {
        return static::getUrl(['workspaceSlug' => $workspace->getRouteKey()]);
    }

    /**
     * The page ALWAYS allows entry; the real boundary lives in mount().
     *
     * This is deliberate, and it matches Filament's own default (which is
     * `return true`). Filament answers a false `canAccess()` on a page that
     * takes a route parameter with **403**. A 403 on `/workspaces/{slug}`
     * confirms that the slug exists, leaking the existence of another tenant's
     * workspace. Returning true lets `mount()` run `authorizeWorkspaceReach()`,
     * which answers **404** — the correct answer for "this object is not yours".
     *
     * Nothing is exposed by allowing entry: `mount()` aborts before
     * `$this->workspace` is assigned, so the view renders nothing, and every
     * query in this class is additionally filtered by reachable sets.
     * `shouldRegisterNavigation()` is false, and the sidebar entry is gated by
     * capability in ProductNavigation.
     */
    public static function canAccess(): bool
    {
        return true;
    }

    public function mount(string $workspaceSlug): void
    {
        $resolved = Workspace::query()->where('slug', $workspaceSlug)->first();

        abort_if($resolved === null, 404);

        // Server-side scope enforcement. Never trust the slug alone.
        PlatformAccess::current()->access()->authorizeWorkspaceReach($resolved);

        $this->workspace = $resolved;
    }

    public function getTitle(): string
    {
        return $this->workspace?->name ?? 'Workspace';
    }

    public function getSubheading(): ?string
    {
        $workspace = $this->workspace;
        if (! $workspace) {
            return null;
        }

        $parts = array_filter([
            $workspace->displayKind(),
            $workspace->status === 'active' ? null : ucfirst($workspace->status),
            $workspace->primary_contact_name,
        ]);

        return implode(' · ', $parts) ?: null;
    }

    // ── Data ───────────────────────────────────────────────────────────

    /** @return Collection<int, Project> Projects in this workspace the user may see. */
    public function projects(): Collection
    {
        if (! $this->workspace) {
            return collect();
        }

        return Project::query()
            ->where('workspace_id', $this->workspace->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (Project $p): bool => PlatformAccess::current()->canReachProject($p))
            ->values();
    }

    /** @return Collection<int, WorkspaceMember> */
    public function members(): Collection
    {
        if (! $this->workspace) {
            return collect();
        }

        return $this->workspace->memberships()
            ->with('user')
            ->where('status', 'active')
            ->get();
    }

    public function healthSummary(): array
    {
        $projects = $this->projects();

        return [
            'total' => $projects->count(),
            'healthy' => $projects->where('health_status', 'healthy')->count(),
            'attention' => $projects->whereIn('health_status', ['unhealthy', 'degraded'])->count(),
            'unknown' => $projects->whereNotIn('health_status', ['healthy', 'unhealthy', 'degraded'])->count(),
        ];
    }

    /**
     * Issues requiring attention, phrased in product language.
     *
     * @return list<array{severity: string, title: string, detail: string, url: ?string}>
     */
    public function attentionItems(): array
    {
        $items = [];

        foreach ($this->projects() as $project) {
            if ($project->health_status === 'unhealthy') {
                $items[] = [
                    'severity' => 'danger',
                    'title' => $project->name.' needs attention',
                    'detail' => 'The last health check did not pass.',
                    'url' => ProjectResource::getUrl('overview', ['record' => $project]),
                ];
            } elseif ($project->health_status === 'unknown') {
                $items[] = [
                    'severity' => 'warning',
                    'title' => $project->name.' has no health result yet',
                    'detail' => 'Connect a source and run a check to get a conclusive status.',
                    'url' => ProjectResource::getUrl('connect', ['record' => $project]),
                ];
            }
        }

        if ($this->projects()->isEmpty()) {
            $items[] = [
                'severity' => 'neutral',
                'title' => 'No projects yet',
                'detail' => 'A project is one backend you migrate. Create the first one to get started.',
                'url' => null,
            ];
        }

        return $items;
    }

    public function canCreateProject(): bool
    {
        return $this->workspace !== null
            && PlatformAccess::current()->allowsWorkspace(Capability::PROJECTS_CREATE, $this->workspace);
    }

    public function canManageMembers(): bool
    {
        return $this->workspace !== null
            && PlatformAccess::current()->allowsWorkspace(Capability::WORKSPACE_MEMBERS_MANAGE, $this->workspace);
    }

    public function canUseAi(): bool
    {
        return $this->workspace !== null
            && PlatformAccess::current()->allowsWorkspace(Capability::AI_USE, $this->workspace);
    }

    // ── Primary actions ────────────────────────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            Action::make('askAi')
                ->label('Ask Nexus AI')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn (): bool => $this->canUseAi())
                ->url(fn (): string => NexusAi::urlForWorkspace($this->workspace))
                ->openUrlInNewTab(false),

            Action::make('inviteMember')
                ->label('Invite member')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->visible(fn (): bool => $this->canManageMembers())
                ->modalHeading('Add a member to '.($this->workspace?->name ?? 'this workspace'))
                ->modalDescription('Members can reach every project in this workspace at the role you choose. To restrict someone to a single project, use project-level access instead.')
                ->modalSubmitActionLabel('Add member')
                ->schema([
                    TextInput::make('email')
                        ->label('Email address')
                        ->email()
                        ->required()
                        ->helperText('The person must already have an account on this platform, or be invited by an operator.'),
                    Select::make('role')
                        ->label('Role')
                        ->options(fn (): array => $this->roleOptions())
                        ->default(Roles::WORKSPACE_MEMBER)
                        ->required()
                        ->selectablePlaceholder(false)
                        ->helperText('Roles are bundles of permissions. The exact permissions are shown on the Members screen.'),
                ])
                ->action(function (array $data): void {
                    // Execution-time re-check (§17).
                    if (! $this->canManageMembers()) {
                        abort(403, 'Missing capability: '.Capability::WORKSPACE_MEMBERS_MANAGE);
                    }

                    $user = User::query()->where('email', $data['email'])->first();

                    if (! $user) {
                        Notification::make()
                            ->title('No account with that email')
                            ->body('Ask a platform operator to create the account first, then add them here.')
                            ->warning()
                            ->send();

                        return;
                    }

                    if (! $this->workspace->memberships()->where('user_id', $user->id)->exists()) {
                        WorkspaceMember::create([
                            'workspace_id' => $this->workspace->id,
                            'user_id' => $user->id,
                            'role' => $data['role'],
                            'status' => 'active',
                            'invited_by' => auth()->id(),
                            'joined_at' => now(),
                        ]);
                    }

                    Notification::make()
                        ->title('Member added')
                        ->body($user->name.' now has access to '.$this->workspace->name.'.')
                        ->success()
                        ->send();
                }),

            Action::make('newProject')
                ->label('New project')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => $this->canCreateProject())
                ->url(fn (): string => ProjectResource::getUrl('create', [
                    'workspace' => $this->workspace?->id,
                ])),
        ];
    }

    /** @return array<string, string> */
    private function roleOptions(): array
    {
        $out = [];
        foreach (Roles::workspaceRoles() as $role) {
            $out[$role] = Roles::label($role);
        }

        return $out;
    }
}
