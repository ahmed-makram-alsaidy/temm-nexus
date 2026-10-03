<?php

namespace App\Filament\Pages;

use App\Filament\Support\PlatformAccess;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Access\Capability;
use App\Services\Ai\Actions\ActionBroker;
use App\Services\Ai\AiContext;
use App\Services\Ai\ConversationEngine;
use App\Services\Ai\ModelRouter;
use App\Services\Ai\Scope;
use App\Services\Ai\ToolDispatcher;
use App\Services\Ai\ToolRegistry;
use App\Services\ControlPlane\AdminAudit;
use App\Services\Product\ComponentRegistry;
use App\Services\Product\InspectionContext;
use App\Services\Product\UiPreferenceService;
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
 *  - Inspect Mode (Phase I): a user-selected component is attached to the
 *    conversation. The browser sends only a KEY; this class resolves it
 *    against `ComponentRegistry` with the acting user's own authorisation,
 *    so an unknown or unpermitted component never reaches a model.
 *  - Structured UI preferences (Phase I): a whitelisted, typed appearance
 *    change with an explicit preview step and an explicit apply. No free-form
 *    CSS, no markup — that would be a code change and belongs to the isolated
 *    patch workflow.
 *
 * WHAT IS NOT REAL HERE (stated plainly, and in the UI)
 *  - Action tools and the approval flow (Phase J). This page cannot mutate
 *    anything but the acting user's own UI preferences.
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

    // ── Inspect Mode (Phase I) ─────────────────────────────────────────

    /**
     * The component selected in Inspect Mode, held as the validated KEY only.
     *
     * The browser never sends a description; `attachComponent()` resolves the
     * key against `ComponentRegistry` and refuses anything unknown or
     * unauthorised. Everything the UI later shows (label, page, data source)
     * is re-derived from the registry, so client-supplied text can never
     * reach the panel, the prompt, or the audit ledger.
     */
    public ?string $inspectComponentKey = null;

    /**
     * A proposed UI preference awaiting confirmation: the preview card shows
     * current and proposed values and the affected scope, and NOTHING is
     * persisted until `applyUiPreference()` is clicked.
     *
     * @var array{component: string, adjustment: string, value: mixed, current: mixed, scope: string}|null
     */
    public ?array $uiProposal = null;

    /**
     * The picker's `adjustment:value` choice from the Inspect panel. Parsed
     * strictly server-side; a malformed or unpermitted choice is refused by
     * the same validation every other path goes through.
     */
    public string $uiFormChoice = '';

    /**
     * The whitelisted choices for the ATTACHED component's appearance, with
     * no-op options (things already in the proposed state) removed.
     *
     * @return array<string, string> `adjustment:value` => label
     */
    public function uiChoices(): array
    {
        $inspection = $this->inspection();
        if ($inspection === null) {
            return [];
        }

        $ids = $this->preferenceScopeIds($inspection);
        $service = UiPreferenceService::for(auth()->user());
        $choices = [];

        foreach ($inspection->allowedAdjustments as $adjustment) {
            $current = $service->value(
                $inspection->componentKey,
                $adjustment,
                $ids['workspace_id'],
                $ids['project_id'],
            );

            switch ($adjustment) {
                case 'visibility':
                    if ($current !== false) {
                        $choices['visibility:0'] = 'Hide this component';
                    }
                    if ($current !== true) {
                        $choices['visibility:1'] = 'Show this component';
                    }
                    break;
                case 'density':
                    foreach (['compact' => 'Compact density', 'comfortable' => 'Comfortable density', 'spacious' => 'Spacious density'] as $value => $label) {
                        if ($current !== $value) {
                            $choices['density:'.$value] = $label;
                        }
                    }
                    break;
                case 'expanded_by_default':
                    if ($current !== true) {
                        $choices['expanded_by_default:1'] = 'Start expanded';
                    }
                    if ($current !== false) {
                        $choices['expanded_by_default:0'] = 'Start collapsed';
                    }
                    break;
                case 'position':
                    foreach (['first' => 'Move to first', 'last' => 'Move to last'] as $value => $label) {
                        if ($current !== $value) {
                            $choices['position:'.$value] = $label;
                        }
                    }
                    break;
            }
        }

        return $choices;
    }

    /** Entry point from the panel's picker: parse the choice and propose. */
    public function previewUiAdjustment(): void
    {
        $inspection = $this->inspection();
        $choice = $this->uiFormChoice;

        $this->uiFormChoice = '';

        if ($inspection === null || ! str_contains($choice, ':')) {
            return;
        }

        [$adjustment, $rawValue] = explode(':', $choice, 2);

        $this->proposeUiAdjustment($inspection->componentKey, $adjustment, $rawValue);
    }

    /**
     * Effective appearance preferences for THIS page's own components, in one
     * query. The view reads this to apply visibility and density — the same
     * preferences the Inspect panel writes.
     *
     * @return array<string, array<string, mixed>>
     */
    public function aiPreferences(): array
    {
        $context = $this->context();

        return UiPreferenceService::for(auth()->user())->effectiveForComponents(
            ['ai.transcript', 'ai.tools', 'ai.scope_banner'],
            $context->workspace?->getKey(),
            $context->project?->getKey(),
        );
    }

    /** Human wording for a preference value, per adjustment, for the preview. */
    public function preferenceValueLabel(string $adjustment, mixed $value): string
    {        if ($adjustment === 'expanded_by_default') {
            return $value === true ? 'Starts expanded' : 'Starts collapsed';
        }

        if ($adjustment === 'visibility') {
            return $value === true ? 'Shown' : 'Hidden';
        }

        if ($adjustment === 'density') {
            return match ($value) {
                'compact' => 'Compact',
                'spacious' => 'Spacious',
                default => 'Comfortable',
            };
        }

        if ($adjustment === 'position') {
            return match ($value) {
                'first' => 'First',
                'last' => 'Last',
                default => 'Natural position',
            };
        }

        return (string) $value;
    }

    protected ?InspectionContext $inspectionContext = null;

    /**
     * Accept a component selection from the client.
     *
     * The key is validated here, server-side, against the acting user's REAL
     * authorisation. An unknown key, or a component whose capability the user
     * does not hold at the current scope, is refused silently — the chip
     * simply does not appear, and nothing reaches the model. Refusing without
     * an error message also means probing this endpoint reveals nothing.
     */
    public function attachComponent(string $key): void
    {
        $resolved = $this->resolveInspection($key);

        if ($resolved === null) {
            $this->inspectComponentKey = null;
            $this->inspectionContext = null;

            return;
        }

        // Same component already attached: no-op, no duplicate audit rows.
        if ($this->inspectComponentKey === $resolved->componentKey) {
            return;
        }

        $this->inspectComponentKey = $resolved->componentKey;
        $this->inspectionContext = $resolved;
        // A new component invalidates any preview built against the old one.
        $this->uiProposal = null;

        // Attaching a component is a meaningful AI action, so it is recorded —
        // including which component and at what scope.
        try {
            $context = $this->context();
            AdminAudit::record(
                'AI_INSPECT_CONTEXT_ATTACHED',
                $context->project,
                'ui_component',
                $resolved->componentKey,
                $resolved->auditPayload(),
            );
        } catch (\Throwable) {
            // Auditing must not break the selection.
        }
    }

    public function clearInspectedComponent(): void
    {
        $this->inspectComponentKey = null;
        $this->inspectionContext = null;
        $this->uiProposal = null;
    }

    /** Resolve the held key into a validated context, or null. */
    public function inspection(): ?InspectionContext
    {
        if ($this->inspectComponentKey === null) {
            return null;
        }

        return $this->inspectionContext ??= $this->resolveInspection($this->inspectComponentKey);
    }

    /** Components on THIS page/scope the user may inspect, for the picker hint. */
    public function inspectableHere(): array
    {
        $out = [];
        foreach (array_keys(ComponentRegistry::COMPONENTS) as $key) {
            if (($resolved = $this->resolveInspection($key)) !== null) {
                $out[] = ['key' => $key, 'label' => $resolved->label, 'page' => $resolved->page];
            }
        }

        return $out;
    }

    /** Resolve a key under the CURRENT user, scope, and context — or null. */
    protected function resolveInspection(string $key): ?InspectionContext
    {
        $context = $this->context();

        return InspectionContext::resolve(
            $key,
            $context->access,
            $context->workspace,
            $context->project,
        );
    }

    // ── Structured UI preferences (Phase I) ────────────────────────────

    /**
     * Propose a whitelisted appearance change and show the preview card.
     *
     * No write happens here. The proposal is validated against the registry
     * whitelist AND the component must be one this user may inspect at the
     * current scope — the same authorisation as attaching it.
     */
    public function proposeUiAdjustment(string $component, string $adjustment, string|int|bool $value): void
    {
        $this->uiProposal = null;

        $resolved = $this->resolveInspection($component);
        if ($resolved === null || ! in_array($adjustment, $resolved->allowedAdjustments, true)) {
            return;
        }

        $normalised = $this->normalisePreferenceValue($adjustment, $value);
        $check = UiPreferenceService::validate($component, $adjustment, $normalised);
        if (! $check['ok']) {
            return;
        }

        $this->uiProposal = [
            'component' => $component,
            'adjustment' => $adjustment,
            'value' => $normalised,
            'current' => $this->currentPreference($resolved, $adjustment),
            'scope' => $this->preferenceScopeLabel($resolved),
        ];

        try {
            $context = $this->context();
            AdminAudit::record(
                'AI_ACTION_PROPOSED',
                $context->project,
                'ui_preference',
                $component,
                [
                    'kind' => 'ui_preference',
                    'adjustment' => $adjustment,
                    'value' => $normalised,
                    'scope' => $resolved->scope->value,
                    'actor_kind' => 'user',
                ],
            );
        } catch (\Throwable) {
            // Auditing must not block the preview.
        }
    }

    /**
     * Apply the pending proposal. Everything is re-validated and
     * re-authorised HERE — the preview being visible is not authority
     * (the same execution-time rule the tool layer follows).
     */
    public function applyUiPreference(): void
    {
        $proposal = $this->uiProposal;
        if ($proposal === null) {
            return;
        }

        $resolved = $this->resolveInspection($proposal['component']);
        if ($resolved === null
            || ! in_array($proposal['adjustment'], $resolved->allowedAdjustments, true)
            || ! UiPreferenceService::validate($proposal['component'], $proposal['adjustment'], $proposal['value'])['ok']) {
            $this->uiProposal = null;

            return;
        }

        $ids = $this->preferenceScopeIds($resolved);
        $result = UiPreferenceService::for(auth()->user())->apply(
            $proposal['component'],
            $proposal['adjustment'],
            $proposal['value'],
            $ids['workspace_id'],
            $ids['project_id'],
        );

        $this->uiProposal = null;

        if ($result['ok']) {
            try {
                $context = $this->context();
                AdminAudit::record(
                    'AI_ACTION_APPLIED',
                    $context->project,
                    'ui_preference',
                    $proposal['component'],
                    [
                        'kind' => 'ui_preference',
                        'adjustment' => $proposal['adjustment'],
                        'value' => $proposal['value'],
                        'scope' => $resolved->scope->value,
                        'actor_kind' => 'user',
                    ],
                );
            } catch (\Throwable) {
                // Auditing must not break the apply.
            }
        }
    }

    /** Discard the pending proposal without writing anything. */
    public function cancelUiPreference(): void
    {
        if ($this->uiProposal === null) {
            return;
        }

        $proposal = $this->uiProposal;
        $this->uiProposal = null;

        try {
            $context = $this->context();
            AdminAudit::record(
                'AI_ACTION_REJECTED',
                $context->project,
                'ui_preference',
                $proposal['component'],
                [
                    'kind' => 'ui_preference',
                    'adjustment' => $proposal['adjustment'],
                    'scope' => $this->inspection()?->scope->value ?? 'platform',
                    'actor_kind' => 'user',
                ],
            );
        } catch (\Throwable) {
            // Auditing must not break a cancel.
        }
    }

    /** Coerce a form-supplied value into the adjustment's type. */
    protected function normalisePreferenceValue(string $adjustment, string|int|bool $value): mixed
    {
        if (in_array($adjustment, ['visibility', 'expanded_by_default'], true)) {
            if (is_bool($value)) {
                return $value;
            }

            return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
        }

        return is_int($value) ? (string) $value : (string) $value;
    }

    /** The effective current value, for the preview's "Current" line. */
    protected function currentPreference(InspectionContext $resolved, string $adjustment): mixed
    {
        $ids = $this->preferenceScopeIds($resolved);

        return UiPreferenceService::for(auth()->user())
            ->value($resolved->componentKey, $adjustment, $ids['workspace_id'], $ids['project_id']);
    }

    /** Platform components adjust the user's global view; scoped ones their scope view. */
    protected function preferenceScopeIds(InspectionContext $resolved): array
    {
        return match ($resolved->scope) {
            Scope::PROJECT => ['workspace_id' => null, 'project_id' => $resolved->project?->getKey()],
            Scope::WORKSPACE => ['workspace_id' => $resolved->workspace?->getKey(), 'project_id' => null],
            Scope::PLATFORM => ['workspace_id' => null, 'project_id' => null],
        };
    }

    protected function preferenceScopeLabel(InspectionContext $resolved): string
    {
        return match ($resolved->scope) {
            Scope::PROJECT => 'You, in project '.($resolved->project?->name ?? 'unknown'),
            Scope::WORKSPACE => 'You, in workspace '.($resolved->workspace?->name ?? 'unknown'),
            Scope::PLATFORM => 'You, everywhere (personal preference)',
        };
    }

    // ── Inspect quick actions (Phase I §I.6/§I.7) ──────────────────────

    /** "Explain this component" — a grounded question about the selection. */
    public function explainComponent(): void
    {
        $inspection = $this->inspection();
        if ($inspection === null) {
            return;
        }

        $this->message = 'Explain the "'.$inspection->label.'" component on the '
            .$inspection->page.' page: what it represents, what data it draws from, and its current state here.';
        $this->send();
    }

    /** "Diagnose this" — check the real state behind the selection. */
    public function diagnoseComponent(): void
    {
        $inspection = $this->inspection();
        if ($inspection === null) {
            return;
        }

        $this->message = 'Diagnose the "'.$inspection->label.'" component: check its real underlying state '
            .'with the read tools available at this scope and report what is normal, what is not, and what needs attention.';
        $this->send();
    }

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
        return __('ai.subtitle');
    }

    public function mount(): void
    {
        $this->requestedScope = request()->query('scope');
        $this->requestedProjectId = request()->query('project') !== null
            ? (int) request()->query('project')
            : null;
        $this->requestedWorkspaceSlug = request()->query('workspace');

        // 0.4.0-rc.5 (B.9) — wizard "Ask Nexus AI" deep links prefill the
        // question. The user still presses send; the assistant can never
        // change connection settings from a link.
        $topic = request()->query('topic');
        if (is_string($topic) && $topic !== '' && $this->requestedScope === null && $this->requestedProjectId === null) {
            $this->message = mb_substr($topic, 0, 500);
        }
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
            $this->error = __('ai.no_permission_scope');

            return;
        }

        // Record the question so the transcript reads correctly even if the
        // request fails.
        $this->transcript[] = ['role' => 'user', 'text' => $text, 'tools' => []];
        $this->message = '';

        // Phase I: a selected component travels with the turn as validated
        // context. `inspection()` re-resolves the key under the CURRENT
        // authorisation, so a revoked user's selection detaches itself.
        $engine = new ConversationEngine($context, new ModelRouter, $this->inspection());

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
            // Phase J: plans the model PROPOSED this turn — cards awaiting a
            // human decision. Nothing in here has executed.
            'actions' => $result['actions'] ?? [],
        ];
    }

    public function clearConversation(): void
    {
        $this->transcript = [];
        $this->error = null;
        $this->lastRoute = null;
    }

    // ── Action approvals (Phase J) ─────────────────────────────────────

    /**
     * Approve & Apply a proposed action plan.
     *
     * The client supplies ONLY the plan id — every argument, the scope, the
     * fingerprint, and the authorisation live in the immutable plan row and
     * are re-checked server-side at this exact moment. A revoked user, an
     * expired plan, a tampered world, or a double click is refused by the
     * broker, and the card shows that honestly.
     */
    public function approveActionPlan(string $planId): void
    {
        $broker = new ActionBroker($this->context());
        $result = $broker->approveAndExecute($planId, auth()->user());

        $this->refreshActionCard($result['plan']);
    }

    /** Decline a proposed action. Cancelling never executes anything. */
    public function rejectActionPlan(string $planId): void
    {
        $broker = new ActionBroker($this->context());
        $result = $broker->reject($planId, auth()->user());

        if ($result['ok'] ?? false) {
            $this->refreshActionCard($result['plan']);
        }
    }

    /** Replace a transcript card with the plan's current, honest state. */
    protected function refreshActionCard(\App\Models\ActionPlan $plan): void
    {
        foreach ($this->transcript as $index => $turn) {
            foreach (($turn['actions'] ?? []) as $cardIndex => $card) {
                if (($card['plan_id'] ?? null) === $plan->getKey()) {
                    $this->transcript[$index]['actions'][$cardIndex] = $plan->card();

                    return;
                }
            }
        }
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
