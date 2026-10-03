<?php

namespace App\Filament\Pages;

use App\Filament\Support\PlatformAccess;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Access\Capability;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * 0.4.0 Phase C — CLIENTS & WORKSPACES.
 *
 * The PLATFORM-scope index of every workspace the signed-in user may see.
 * This is the entry point to the middle scope of the product:
 *
 *     PLATFORM └─ WORKSPACE └─ PROJECT
 *
 * SECURITY
 * The list is built from `Access::accessibleWorkspaces()` — never from the
 * request, never from the session, and never from hidden navigation. A user
 * with no memberships sees an empty state, not other tenants' clients.
 */
class Workspaces extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Clients & Workspaces';

    public static function getNavigationLabel(): string
    {
        return __('workspaces.title');
    }

    protected static ?string $title = 'Clients & Workspaces';

    public function getTitle(): string
    {
        return __('workspaces.title');
    }

    protected static ?string $slug = 'workspaces';

    protected string $view = 'filament.pages.workspaces';

    /**
     * Entry check.
     *
     * This is a CLASS-level check with no record, so it cannot test a
     * platform-scope capability: a workspace owner legitimately holds
     * `workspaces.view` only inside their own workspace and would be wrongly
     * refused. Entry is granted to anyone who can reach at least one workspace.
     * The listing itself is then filtered by `accessibleWorkspaces()`, so a user
     * with reach sees exactly their own clients and nothing else.
     */
    public static function canAccess(): bool
    {
        return PlatformAccess::current()->access()->accessibleWorkspaces()->isNotEmpty();
    }

    public function getSubheading(): ?string
    {
        return __('workspaces.subtitle');
    }

    /**
     * Workspaces visible to the current user, with their projects.
     *
     * @return Collection<int, Workspace>
     */
    public function workspaces(): Collection
    {
        return PlatformAccess::current()->access()->accessibleWorkspaces();
    }

    /** Projects grouped by workspace id, resolved with legal scope. */
    public function projectsByWorkspace(): Collection
    {
        return PlatformAccess::current()
            ->access()
            ->accessibleProjects()
            ->groupBy('workspace_id');
    }

    /** Count of ungrouped legacy projects the user may see. */
    public function ungroupedCount(): int
    {
        return (int) (PlatformAccess::current()
            ->access()
            ->accessibleProjects()
            ->whereNull('workspace_id')
            ->count());
    }

    public function canCreate(): bool
    {
        return PlatformAccess::current()->allowsPlatform(Capability::WORKSPACES_CREATE);
    }

    /** Statistics for one workspace, computed from already-scoped projects. */
    public function statsFor(Workspace $workspace): array
    {
        /** @var Collection<int, Project> $projects */
        $projects = $this->projectsByWorkspace()->get($workspace->id, collect());

        return [
            'projects' => $projects->count(),
            'healthy' => $projects->where('health_status', 'healthy')->count(),
            'attention' => $projects->whereIn('health_status', ['unhealthy', 'degraded'])->count(),
            'members' => $workspace->memberships()->where('status', 'active')->count(),
        ];
    }

    public function ownerName(Workspace $workspace): ?string
    {
        return $workspace->owner()?->name;
    }

    // ── Actions ────────────────────────────────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createWorkspace')
                ->label(__('workspaces.new_workspace'))
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => $this->canCreate())
                ->modalHeading(__('workspaces.create_workspace_heading'))
                ->modalDescription('A workspace is one client, company, or internal team. Projects live inside it.')
                ->modalSubmitActionLabel(__('workspaces.create_workspace_submit'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('workspaces.workspace_name'))
                        ->required()
                        ->maxLength(120)
                        ->placeholder('e.g. Nayrouz'),
                    Select::make('kind')
                        ->label(__('workspaces.type'))
                        ->options([
                            'client' => __('workspaces.type_client'),
                            'company' => __('workspaces.type_company'),
                            'team' => __('workspaces.type_team'),
                            'internal' => __('workspaces.type_internal'),
                        ])
                        ->default('client')
                        ->required()
                        ->selectablePlaceholder(false),
                    Textarea::make('description')
                        ->label(__('workspaces.workspace_description'))
                        ->rows(2)
                        ->maxLength(500)
                        ->helperText(__('workspaces.workspace_description_helper')),
                ])
                ->action(function (array $data): void {
                    // Re-check at execution time: the form being open is not
                    // permission to act.
                    if (! $this->canCreate()) {
                        abort(403, 'Missing capability: '.Capability::WORKSPACES_CREATE);
                    }

                    $workspace = Workspace::create([
                        'name' => $data['name'],
                        'slug' => $this->uniqueSlug($data['name']),
                        'kind' => $data['kind'],
                        'description' => $data['description'] ?? null,
                        'status' => 'active',
                    ]);

                    Notification::make()
                        ->title(__('workspaces.workspace_created'))
                        ->body($workspace->name.' is ready. Add a project to it next.')
                        ->success()
                        ->send();

                    $this->redirect(WorkspaceDetail::urlFor($workspace));
                }),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $candidate = $base;
        $i = 2;

        while (Workspace::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$i++;
        }

        return $candidate;
    }
}
