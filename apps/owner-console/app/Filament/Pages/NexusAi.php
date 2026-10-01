<?php

namespace App\Filament\Pages;

use App\Filament\Support\PlatformAccess;
use App\Models\AiProviderConfig;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Access\Capability;
use App\Services\Ai\AiContext;
use App\Services\Ai\Scope;
use App\Services\Ai\ToolDispatcher;
use App\Services\Ai\ToolRegistry;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * 0.4.0 §15/§16/§28/§29 — the global Nexus AI surface.
 *
 * HONEST SCOPE STATEMENT
 * ----------------------
 * This page is **scaffolded, not complete**. It delivers:
 *   - the persistent platform-level entry point,
 *   - the explicit, route-derived CONTEXT SCOPE (Platform / Workspace /
 *     Project) rendered so the user always sees it,
 *   - the permission-filtered list of read tools available in that scope,
 *     produced by the enforcing `ToolDispatcher` (so what is listed is exactly
 *     what the acting user may actually run),
 *   - the quick-action starters.
 *
 * It does NOT yet deliver: provider/model conversation, streaming, tool
 * execution against live telemetry, Inspect Mode capture, or the approval flow
 * for mutations. Those are Phases G–J and are reported as outstanding rather
 * than claimed. Nothing on this page can mutate the platform.
 */
class NexusAi extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Nexus AI';

    protected static ?string $title = 'Nexus AI';

    protected static ?string $slug = 'nexus-ai';

    protected string $view = 'filament.pages.nexus-ai';

    /** Requested scope hint from a deep link; validated before use. */
    public ?string $requestedScope = null;

    public ?int $requestedProjectId = null;

    public ?string $requestedWorkspaceSlug = null;

    public static function canAccess(): bool
    {
        // Entry requires the weakest AI capability; the per-scope capability is
        // checked when a scope is actually resolved.
        return PlatformAccess::current()->allowsPlatform(Capability::AI_USE)
            || PlatformAccess::current()->allowsPlatform(Capability::AI_PROJECT)
            || PlatformAccess::current()->allowsPlatform(Capability::AI_WORKSPACE)
            || PlatformAccess::current()->allowsPlatform(Capability::AI_PLATFORM);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): ?string
    {
        return 'Ask about your platform, a client workspace, or one project. The assistant can only see and do what you can.';
    }

    public function mount(): void
    {
        $this->requestedScope = request()->query('scope');
        $this->requestedProjectId = request()->query('project') !== null
            ? (int) request()->query('project')
            : null;
        $this->requestedWorkspaceSlug = request()->query('workspace');
    }

    /**
     * Resolve the conversation context.
     *
     * A deep link may NARROW the scope (e.g. from the platform page to a project
     * you can already reach) but can never widen it: every candidate is put
     * through the same reachability checks, and a scope whose capability is not
     * held falls back to Platform rather than failing open.
     */
    public function context(): AiContext
    {
        $access = PlatformAccess::current()->access();

        if ($this->requestedProjectId !== null) {
            $project = Project::query()->find($this->requestedProjectId);
            if ($project && $access->canReachProject($project)) {
                $candidate = AiContext::project($access, $project, $this->pageKey());
                if ($candidate->isOpenable()) {
                    return $candidate;
                }
            }
        }

        if ($this->requestedWorkspaceSlug !== null) {
            $workspace = Workspace::query()->where('slug', $this->requestedWorkspaceSlug)->first();
            if ($workspace && $access->canReachWorkspace($workspace)) {
                $candidate = AiContext::workspace($access, $workspace, $this->pageKey());
                if ($candidate->isOpenable()) {
                    return $candidate;
                }
            }
        }

        return AiContext::platform($access, $this->pageKey());
    }

    private function pageKey(): string
    {
        return 'nexus-ai';
    }

    /** The scope label rendered at the top of the panel (§16). */
    public function contextLabel(): string
    {
        return $this->context()->label();
    }

    /**
     * Read tools actually available to this user in this scope, straight from
     * the enforcing dispatcher.
     *
     * @return list<array{name: string, description: string, label: string}>
     */
    public function availableTools(): array
    {
        $dispatcher = new ToolDispatcher($this->context());
        $out = [];

        foreach ($dispatcher->availableTools() as $name) {
            $definition = ToolRegistry::definition($name);
            if ($definition === null) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'description' => $definition['description'],
                'label' => ToolRegistry::label($name),
            ];
        }

        return $out;
    }

    /** §28 quick actions — convenience starters, not separate logic. */
    public function quickActions(): array
    {
        $actions = [
            ['label' => 'Check platform health', 'icon' => 'heroicon-o-heart', 'prompt' => 'Check platform health'],
            ['label' => 'Review recent errors', 'icon' => 'heroicon-o-exclamation-triangle', 'prompt' => 'Review recent errors'],
            ['label' => 'Diagnose an issue', 'icon' => 'heroicon-o-wrench', 'prompt' => 'Diagnose an issue'],
        ];

        $context = $this->context();

        if ($context->scope !== Scope::PLATFORM) {
            $actions[] = ['label' => 'Explain this page', 'icon' => 'heroicon-o-document-text', 'prompt' => 'Explain this page'];
        }

        if ($context->scope === Scope::PROJECT) {
            $actions[] = ['label' => 'Check migration readiness', 'icon' => 'heroicon-o-clipboard-document-check', 'prompt' => 'Is this project ready to cut over?'];
            $actions[] = ['label' => 'Check Live Sync', 'icon' => 'heroicon-o-arrow-path', 'prompt' => 'Is Live Sync up to date?'];
        }

        return $actions;
    }

    /** True when no AI provider has been configured yet (§32 empty state). */
    public function hasProvider(): bool
    {
        try {
            return AiProviderConfig::query()->where('enabled', true)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function canConfigureAi(): bool
    {
        return PlatformAccess::current()->allowsPlatform(Capability::AI_CONFIGURE);
    }
}
