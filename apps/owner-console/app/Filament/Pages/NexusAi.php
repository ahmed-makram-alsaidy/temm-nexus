<?php

namespace App\Filament\Pages;

use App\Filament\Support\PlatformAccess;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Access\Capability;
use App\Services\Ai\AiContext;
use App\Services\Ai\ConversationEngine;
use App\Services\Ai\ModelRouter;
use App\Services\Ai\Scope;
use App\Services\Ai\ToolDispatcher;
use App\Services\Ai\ToolRegistry;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * 0.4.0 §15/§16/§19/§28/§29 — the global Nexus AI surface.
 *
 * WHAT IS REAL HERE
 *  - The persistent platform-level entry point.
 *  - The explicit, route-derived CONTEXT SCOPE, always rendered.
 *  - Working conversations through `ConversationEngine`, against whichever
 *    provider the operator configured. Model routing (default / reasoning /
 *    code) is selectable and falls back to a single provider when only one is
 *    configured (§24).
 *  - Tool activity rendered in product language ("Checking Live Sync…"), never
 *    as raw JSON (§29).
 *  - The permission-filtered tool inventory, produced by the enforcing
 *    dispatcher, so what is listed is exactly what the user may run.
 *
 * WHAT IS NOT REAL HERE (stated plainly, and in the UI)
 *  - Inspect Mode (Phase I).
 *  - Action tools and the approval flow (Phase J). This page is read-only.
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

    /** The drafted question. */
    public string $message = '';

    /** Which model role answers this turn (§24). */
    public string $role = ModelRouter::ROLE_DEFAULT;

    /**
     * The visible transcript. Only user/assistant text and humanised tool
     * labels — never raw payloads.
     *
     * @var list<array{role: string, text: string, tools: list<array{tool: string, label: string, ok: bool, denied: ?string}>}>
     */
    public array $transcript = [];

    public ?string $error = null;

    /** @var array{provider: ?string, model: ?string}|null */
    public ?array $lastRoute = null;

    /**
     * Entry check.
     *
     * This is a CLASS-level check with no scope, so it must NOT ask for a
     * platform-scope capability. A workspace owner holds `ai.use` only inside
     * their own workspace — asking at platform scope refused exactly the people
     * the Workspace layer exists for. Entry is therefore granted to anyone who
     * can reach an object at all; the PER-SCOPE capability is enforced by
     * `AiContext::isOpenable()` when a scope is resolved, and again at send time
     * in `send()`.
     */
    public static function canAccess(): bool
    {
        $access = PlatformAccess::current()->access();

        if ($access->hasPlatformAccess()) {
            return true;
        }

        return $access->accessibleWorkspaces()->isNotEmpty()
            || $access->accessibleProjects()->isNotEmpty();
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

    // ── Context ────────────────────────────────────────────────────────

    /**
     * Resolve the conversation context.
     *
     * A deep link may NARROW the scope but never widen it: every candidate goes
     * through the same reachability checks, and a scope whose capability is not
     * held is never substituted in. When the requested scope is unusable we fall
     * back to a scope the user actually holds rather than failing open.
     */
    public function context(): AiContext
    {
        $access = PlatformAccess::current()->access();

        if ($this->requestedProjectId !== null) {
            $project = Project::query()->find($this->requestedProjectId);
            if ($project && $access->canReachProject($project)) {
                $candidate = AiContext::project($access, $project, 'nexus-ai');
                if ($candidate->isOpenable()) {
                    return $candidate;
                }
            }
        }

        if ($this->requestedWorkspaceSlug !== null) {
            $workspace = Workspace::query()->where('slug', $this->requestedWorkspaceSlug)->first();
            if ($workspace && $access->canReachWorkspace($workspace)) {
                $candidate = AiContext::workspace($access, $workspace, 'nexus-ai');
                if ($candidate->isOpenable()) {
                    return $candidate;
                }
            }
        }

        $platform = AiContext::platform($access, 'nexus-ai');
        if ($platform->isOpenable()) {
            return $platform;
        }

        // A workspace or project member does NOT hold ai.platform, so a
        // platform-scope assistant would refuse them at send time. Rather than
        // show a chat box that always errors, fall back to a scope they DO hold:
        // their single workspace, or their single project. With more than one
        // candidate we stay on Platform and let the UI explain — guessing which
        // client the user meant would be worse than asking.
        $workspaces = $access->accessibleWorkspaces();
        if ($workspaces->count() === 1) {
            $candidate = AiContext::workspace($access, $workspaces->first(), 'nexus-ai');
            if ($candidate->isOpenable()) {
                return $candidate;
            }
        }

        $projects = $access->accessibleProjects();
        if ($projects->count() === 1) {
            $candidate = AiContext::project($access, $projects->first(), 'nexus-ai');
            if ($candidate->isOpenable()) {
                return $candidate;
            }
        }

        return $platform;
    }

    public function contextLabel(): string
    {
        return $this->context()->label();
    }

    // ── Tools ──────────────────────────────────────────────────────────

    /** @return list<array{name: string, description: string, label: string}> */
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

    // ── Sending ────────────────────────────────────────────────────────

    public function send(): void
    {
        $this->error = null;

        $text = trim($this->message);
        if ($text === '') {
            return;
        }

        // Re-resolve the context and re-check the capability AT SEND TIME.
        // Opening the page is not authority to use it (§17).
        $context = $this->context();
        if (! $context->isOpenable()) {
            $this->error = 'You do not have permission to use Nexus AI at this scope.';

            return;
        }

        // Record the question so the transcript reads correctly even if the
        // request fails.
        $this->transcript[] = ['role' => 'user', 'text' => $text, 'tools' => []];
        $this->message = '';

        $engine = new ConversationEngine($context, new ModelRouter);

        // Only user/assistant TEXT is replayed; tool payloads are not carried
        // across turns, which keeps the prompt small and the history clean.
        $history = array_values(array_map(
            fn (array $t): array => ['role' => $t['role'], 'content' => $t['text']],
            array_filter($this->transcript, fn (array $t): bool => $t['text'] !== ''),
        ));

        $result = $engine->turn($text, array_slice($history, 0, -1), $this->role);

        if (! $result['ok']) {
            $this->error = $result['error'];
            // Drop the unanswered question so the user is not left with a
            // dangling turn that looks like it succeeded.
            array_pop($this->transcript);

            return;
        }

        $this->lastRoute = [
            'provider' => $result['provider'],
            'model' => $result['model'],
        ];

        $this->transcript[] = [
            'role' => 'assistant',
            'text' => (string) $result['reply'],
            'tools' => $result['tools'],
        ];
    }

    public function clearConversation(): void
    {
        $this->transcript = [];
        $this->error = null;
        $this->lastRoute = null;
    }

    // ── Quick actions (§28) ────────────────────────────────────────────

    /**
     * Convenience starters. They populate the composer — they are not separate
     * logic, and every one of them goes through the same scoped tools.
     *
     * @return list<array{label: string, icon: string, prompt: string}>
     */
    public function quickActions(): array
    {
        $actions = [
            ['label' => 'Check platform health', 'icon' => 'heroicon-o-heart', 'prompt' => 'What needs my attention right now?'],
            ['label' => 'Diagnose an issue', 'icon' => 'heroicon-o-wrench-screwdriver', 'prompt' => 'Are there any problems I should look at?'],
            ['label' => 'Review recent errors', 'icon' => 'heroicon-o-exclamation-triangle', 'prompt' => 'What errors happened recently?'],
        ];

        if ($this->context()->scope !== Scope::PLATFORM) {
            $actions[] = ['label' => 'Explain this page', 'icon' => 'heroicon-o-document-text', 'prompt' => 'Explain what I am looking at.'];
        }

        if ($this->context()->scope === Scope::PROJECT) {
            $actions[] = ['label' => 'Check migration readiness', 'icon' => 'heroicon-o-clipboard-document-check', 'prompt' => 'Is this project ready to cut over?'];
            $actions[] = ['label' => 'Check Live Sync', 'icon' => 'heroicon-o-arrow-path', 'prompt' => 'Is Live Sync up to date?'];
        }

        return $actions;
    }

    public function useQuickAction(string $prompt): void
    {
        $this->message = $prompt;
        $this->send();
    }

    // ── State ──────────────────────────────────────────────────────────

    /** True when at least one provider is enabled. */
    public function hasProvider(): bool
    {
        return (new ModelRouter)->isConfigured();
    }

    /** @return list<array{role: string, label: string, provider: ?string, model: ?string, source: string}> */
    public function routingTable(): array
    {
        return (new ModelRouter)->routingTable();
    }

    public function canConfigureAi(): bool
    {
        return PlatformAccess::current()->allowsPlatform(Capability::AI_CONFIGURE);
    }
}
