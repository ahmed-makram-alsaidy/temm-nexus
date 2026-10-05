<?php

namespace App\Filament\Pages;

use App\Filament\Support\PlatformAccess;
use App\Models\ActionPlan;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Access\Access;
use App\Services\Access\Capability;
use App\Services\Ai\Actions\ActionBroker;
use App\Services\Ai\Actions\ActionRegistry;
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
use Illuminate\Support\Str;

/**
 * 0.4.0 §15/§16/§19/§28/§29 — the global Nexus AI surface.
 * 0.6.0 Phase F — the CHAT-FIRST product experience.
 *
 * WHAT IS REAL HERE
 *  - The persistent platform-level entry point.
 *  - The explicit, route-derived CONTEXT SCOPE, always rendered in product
 *    language ("Project: Acme Website" / "All accessible projects") — never
 *    internal scope ids.
 *  - Working conversations through `ConversationEngine`, against whichever
 *    provider the operator configured. Model routing (default / reasoning /
 *    code) is selectable and falls back to a single provider when only one is
 *    configured (§24).
 *  - DURABLE conversations (Phase F): every turn persists to
 *    `ai_conversations` + `ai_messages`, so a refresh keeps the thread, a
 *    provider failure never corrupts history, and a retry re-uses the failed
 *    user row instead of duplicating the question (§F11, §F24).
 *  - Tool activity rendered in product language ("Checking Live Sync…"), never
 *    as raw JSON (§29), with a grounding indicator on tool-grounded answers.
 *  - The permission-filtered tool inventory — now behind a "What can Nexus AI
 *    do here?" disclosure so the chat and composer lead the page (§F1, §F4).
 *  - Provider failures as CLASSIFIED error cards (auth / unavailable / rate
 *    limited / model unavailable / invalid response) in the Phase A error
 *    pattern — raw statuses and provider text live only in Technical
 *    details (§F8, §F10, §F23).
 *  - Inspect Mode (Phase I): a user-selected component is attached to the
 *    conversation. The browser sends only a KEY; this class resolves it
 *    against `ComponentRegistry` with the acting user's own authorisation,
 *    so an unknown or unpermitted component never reaches a model.
 *  - Structured UI preferences (Phase I): a whitelisted, typed appearance
 *    change with an explicit preview step and an explicit apply. No free-form
 *    CSS, no markup — that would be a code change and belongs to the isolated
 *    patch workflow.
 *  - Safe AI actions (Phase J): the assistant can only PROPOSE; every card is
 *    PLAN → EFFECT PREVIEW → HUMAN APPROVAL → APPLY → VERIFY, and a user who
 *    cannot approve never sees an enabled Approve control (§F19).
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
     * @var list<array{role: string, text: string, tools: list<array{tool: string, label: string, ok: bool, denied: ?string, duration_ms?: int}>, actions?: list<array<string, mixed>>}>
     */
    public array $transcript = [];

    /**
     * The classified error of the latest failed turn (§F8): kind, translated
     * title/body and the Technical details line. Null after any success.
     *
     * @var array{kind: string, title: string, body: string, technical: ?string, retryable: bool}|null
     */
    public ?array $error = null;

    /** @var array{provider: ?string, model: ?string}|null */
    public ?array $lastRoute = null;

    // ── Conversation history (Phase F §F11) ────────────────────────────

    /** The open conversation. Null = a new (unsaved yet) conversation. */
    public ?string $activeConversationId = null;

    /** The history panel is rendered ONLY when opened — history never loads on every render (§F21). */
    public bool $showHistory = false;

    public string $historySearch = '';

    public int $historyPage = 0;

    /** The persisted user row awaiting a retry — retry re-uses it, never duplicates it (§F24). */
    public ?string $retryMessageId = null;

    public const HISTORY_PAGE_SIZE = 8;

    /** Maximum transcript rows loaded when opening a conversation. */
    public const TRANSCRIPT_LIMIT = 100;

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
                        $choices['visibility:0'] = __('ai.hide_this_component');
                    }
                    if ($current !== true) {
                        $choices['visibility:1'] = __('ai.show_this_component');
                    }
                    break;
                case 'density':
                    foreach (['compact' => __('ai.density_compact'), 'comfortable' => __('ai.density_comfortable'), 'spacious' => __('ai.density_spacious')] as $value => $label) {
                        if ($current !== $value) {
                            $choices['density:'.$value] = $label;
                        }
                    }
                    break;
                case 'expanded_by_default':
                    if ($current !== true) {
                        $choices['expanded_by_default:1'] = __('ai.start_expanded');
                    }
                    if ($current !== false) {
                        $choices['expanded_by_default:0'] = __('ai.start_collapsed');
                    }
                    break;
                case 'position':
                    foreach (['first' => __('ai.move_to_first'), 'last' => __('ai.move_to_last')] as $value => $label) {
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
    {
        if ($adjustment === 'expanded_by_default') {
            return $value === true ? __('ai.value_starts_expanded') : __('ai.value_starts_collapsed');
        }

        if ($adjustment === 'visibility') {
            return $value === true ? __('ai.value_shown') : __('ai.value_hidden');
        }

        if ($adjustment === 'density') {
            return match ($value) {
                'compact' => __('ai.density_label_compact'),
                'spacious' => __('ai.density_label_spacious'),
                default => __('ai.density_label_comfortable'),
            };
        }

        if ($adjustment === 'position') {
            return match ($value) {
                'first' => __('ai.position_first'),
                'last' => __('ai.position_last'),
                default => __('ai.position_natural'),
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
            Scope::PROJECT => __('ai.scope_you_in_project', ['name' => $resolved->project?->name ?? __('ai.context_unknown')]),
            Scope::WORKSPACE => __('ai.scope_you_in_workspace', ['name' => $resolved->workspace?->name ?? __('ai.context_unknown')]),
            Scope::PLATFORM => __('ai.scope_you_everywhere'),
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

        $this->message = __('ai.inspect_explain_prompt', ['label' => $inspection->label, 'page' => $inspection->page]);
        $this->send();
    }

    /** "Diagnose this" — check the real state behind the selection. */
    public function diagnoseComponent(): void
    {
        $inspection = $this->inspection();
        if ($inspection === null) {
            return;
        }

        $this->message = __('ai.inspect_diagnose_prompt', ['label' => $inspection->label]);
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

        // Phase F: returning to this context resumes the most recent thread —
        // a refresh (or coming back later) never loses the conversation.
        if ($this->message === '' && $this->hasProvider()) {
            $latest = AiConversation::latestFor($this->context(), auth()->id());
            if ($latest !== null) {
                $this->loadConversation($latest);
            }
        }
    }

    // ── Context ────────────────────────────────────────────────────────

    /**
     * Per-request memo of the resolved context (§F21): the page calls
     * context() from several presentation helpers, and each fresh resolution
     * re-ran the reachability queries. The memo lives only for THIS request
     * (protected state is not persisted across Livewire updates), and it does
     * NOT freeze authorisation — the held Access object re-reads membership
     * on every capability check, exactly as before.
     */
    protected ?AiContext $resolvedContext = null;

    protected ?string $resolvedContextKey = null;

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
        $key = implode('|', [
            $this->requestedScope ?? '',
            (string) ($this->requestedProjectId ?? ''),
            $this->requestedWorkspaceSlug ?? '',
        ]);

        if ($this->resolvedContext !== null && $this->resolvedContextKey === $key) {
            return $this->resolvedContext;
        }

        $context = $this->resolveContext();

        $this->resolvedContext = $context;
        $this->resolvedContextKey = $key;

        return $context;
    }

    protected function resolveContext(): AiContext
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

    /**
     * The context in PRODUCT language (§F2): the human name of the object in
     * scope — never an internal id, never dispatcher vocabulary.
     */
    public function contextLabel(): string
    {
        $context = $this->context();

        return match ($context->scope) {
            Scope::PLATFORM => __('ai.context_platform'),
            Scope::WORKSPACE => __('ai.context_workspace', ['name' => $context->workspace?->name ?? __('ai.context_unknown')]),
            Scope::PROJECT => __('ai.context_project', ['name' => $context->project?->name ?? __('ai.context_unknown')]),
        };
    }

    // ── Tools ──────────────────────────────────────────────────────────

    /**
     * Per-request capability memo for the PRESENTATION listings only (§F21).
     * `Access::allows` deliberately re-reads membership on every call — the
     * dispatcher depends on that at execution time ("no cache to clear") —
     * but re-running it 20+ times to RENDER one page is waste, not safety:
     * every real execution still goes through `ToolDispatcher`/`ActionBroker`
     * with fresh checks.
     *
     * @var array<string, bool>
     */
    protected array $listingCapabilityCache = [];

    protected ?array $toolsListingCache = null;

    protected ?array $actionsListingCache = null;

    /** Cached allows() for listing purposes — never for execution decisions. */
    protected function listingAllows(string $capability): bool
    {
        return $this->listingCapabilityCache[$capability]
            ??= $this->context()->allows($capability);
    }

    /** @return list<array{name: string, description: string, label: string}> */
    public function availableTools(): array
    {
        if ($this->toolsListingCache !== null) {
            return $this->toolsListingCache;
        }

        $context = $this->context();
        $out = [];

        // Mirrors ToolRegistry::availableFor() (scope admits + capability
        // held), with the capability check memoized for this render only.
        foreach (ToolRegistry::TOOLS as $name => $definition) {
            /** @var Scope $toolScope */
            $toolScope = $definition['scope'];
            if (! $context->scope->admits($toolScope) || ! $this->listingAllows($definition['capability'])) {
                continue;
            }

            $out[] = [
                'name' => $name,
                'description' => $definition['description'],
                'label' => ToolRegistry::label($name),
            ];
        }

        return $this->toolsListingCache = $out;
    }

    /**
     * Registered SAFE ACTIONS reachable at this context's scope, filtered to
     * what this user may actually propose — for the capabilities disclosure.
     * Every one of them still requires an explicit human approval card.
     *
     * @return list<array{name: string, label: string, description: string, risk: string}>
     */
    public function availableActions(): array
    {
        if ($this->actionsListingCache !== null) {
            return $this->actionsListingCache;
        }

        $context = $this->context();
        $out = [];

        foreach (ActionRegistry::ACTIONS as $name => $definition) {
            /** @var Scope $actionScope */
            $actionScope = $definition['scope'];
            if (! $context->scope->admits($actionScope) || ! $this->listingAllows($definition['capability'])) {
                continue;
            }

            $out[] = [
                'name' => $name,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'risk' => $definition['risk'],
            ];
        }

        return $this->actionsListingCache = $out;
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
            $this->error = $this->errorPayload('permission');

            return;
        }

        // Phase F: the question persists immediately — a provider failure can
        // never erase what the user asked (§F24).
        $conversation = $this->ensureConversation($context, $text);
        $userRow = AiMessage::query()->create([
            'ai_conversation_id' => $conversation->getKey(),
            'role' => AiMessage::ROLE_USER,
            'text' => $text,
        ]);

        // Record the question so the transcript reads correctly even if the
        // request fails.
        $this->transcript[] = ['role' => 'user', 'text' => $text, 'tools' => []];
        $this->message = '';

        $this->completeTurn($context, $conversation, $text, $userRow, $isRetry = false);
    }

    /**
     * Retry the failed turn (§F8, §F24). Re-uses the SAME persisted user row
     * and the SAME transcript turn — the question is never duplicated.
     */
    public function retryFailedTurn(): void
    {
        if ($this->retryMessageId === null) {
            $this->error = null;

            return;
        }

        $userRow = AiMessage::query()
            ->whereKey($this->retryMessageId)
            ->where('role', AiMessage::ROLE_USER)
            ->first();

        if ($userRow === null) {
            $this->error = null;
            $this->retryMessageId = null;

            return;
        }

        $context = $this->context();
        if (! $context->isOpenable()) {
            $this->error = $this->errorPayload('permission');

            return;
        }

        $conversation = AiConversation::query()->find($userRow->ai_conversation_id);
        if ($conversation === null) {
            $this->error = null;
            $this->retryMessageId = null;

            return;
        }

        $this->error = null;
        $this->completeTurn($context, $conversation, (string) $userRow->text, $userRow, $isRetry = true);
    }

    /**
     * Run the engine turn, then persist + render the outcome. Shared by
     * send() (new user row) and retryFailedTurn() (existing user row).
     */
    protected function completeTurn(AiContext $context, AiConversation $conversation, string $text, AiMessage $userRow, bool $isRetry): void
    {
        // Phase I: a selected component travels with the turn as validated
        // context. `inspection()` re-resolves the key under the CURRENT
        // authorisation, so a revoked user's selection detaches itself.
        $engine = new ConversationEngine($context, new ModelRouter, $this->inspection());

        // Only user/assistant TEXT is replayed; tool payloads are not carried
        // across turns, which keeps the prompt small and the history clean.
        // On a retry, the failed user turn is already the LAST transcript row —
        // it must not be replayed as history on top of the resent question.
        $history = array_values(array_map(
            fn (array $t): array => ['role' => $t['role'], 'content' => $t['text']],
            array_filter($this->transcript, fn (array $t): bool => $t['text'] !== ''),
        ));
        if ($isRetry) {
            array_pop($history);
        }

        $result = $engine->turn($text, array_slice($history, 0, -1), $this->role);

        if (! $result['ok']) {
            $this->failTurn($conversation, $userRow, $result['kind'] ?? 'unexpected', $result['technical'] ?? null);

            return;
        }

        // rc.7 hard guard (§F24) — an empty reply is a provider failure, never
        // a successful blank bubble: refuse it like any other error.
        if (trim((string) $result['reply']) === '') {
            $this->failTurn($conversation, $userRow, 'empty_response', __('ai.error_empty_response_technical'));

            return;
        }

        // The turn succeeded: the user row loses any failure marker, and the
        // answer is persisted with its humanised tool activity and cards.
        $userRow->forceFill(['error' => null])->save();

        $route = [
            'provider' => $result['provider'],
            'model' => $result['model'],
        ];

        AiMessage::query()->create([
            'ai_conversation_id' => $conversation->getKey(),
            'role' => AiMessage::ROLE_ASSISTANT,
            'text' => (string) $result['reply'],
            'tools' => $result['tools'],
            'actions' => $result['actions'] ?? [],
            'route' => $route,
        ]);

        $conversation->forceFill(['last_active_at' => now()])->save();

        $this->retryMessageId = null;
        $this->lastRoute = $route;

        $this->transcript[] = [
            'role' => 'assistant',
            'text' => (string) $result['reply'],
            'tools' => $result['tools'],
            // Phase J: plans the model PROPOSED this turn — cards awaiting a
            // human decision. Nothing in here has executed.
            'actions' => $result['actions'] ?? [],
        ];
    }

    /** Mark a failed turn honestly: no assistant bubble, a classified error card (§F7, §F8). */
    protected function failTurn(AiConversation $conversation, AiMessage $userRow, string $kind, ?string $technical): void
    {
        $payload = $this->errorPayload($kind, $technical);

        $userRow->forceFill(['error' => ['kind' => $kind, 'technical' => $technical]])->save();
        $conversation->forceFill(['last_active_at' => now()])->save();

        $this->error = $payload;
        $this->retryMessageId = $userRow->getKey();
    }

    /**
     * The Phase A error pattern (§F8) with a product-language KIND (§F10).
     * The default face is translated, plain and recoverable; raw provider /
     * transport text lives only in `technical`.
     *
     * @return array{kind: string, title: string, body: string, technical: ?string, retryable: bool}
     */
    protected function errorPayload(string $kind, ?string $technical = null): array
    {
        $known = in_array($kind, ['auth', 'unavailable', 'rate_limited', 'model_unavailable', 'invalid_response', 'empty_response', 'unexpected', 'permission', 'not_configured'], true);
        $kind = $known ? $kind : 'unexpected';

        return [
            'kind' => $kind,
            'title' => __("ai.error_{$kind}_title"),
            'body' => __("ai.error_{$kind}_body"),
            'technical' => $technical,
            // A permission problem or a missing provider is not fixed by
            // pressing Retry — everything else deserves one (§F8).
            'retryable' => ! in_array($kind, ['permission', 'not_configured'], true),
        ];
    }

    // ── Conversation history (Phase F §F11) ────────────────────────────

    /** Start a fresh conversation. Nothing is deleted — the old thread stays in history. */
    public function newConversation(): void
    {
        $this->activeConversationId = null;
        $this->transcript = [];
        $this->error = null;
        $this->retryMessageId = null;
        $this->lastRoute = null;
        $this->showHistory = false;
    }

    /** The bounded, searchable history list for THIS user + THIS context (§F21). */
    public function conversations(): array
    {
        $context = $this->context();

        $query = AiConversation::query()
            ->where('user_id', auth()->id())
            ->where('scope', $context->scope->value)
            ->where('workspace_id', $context->workspace?->getKey())
            ->where('project_id', $context->project?->getKey())
            ->whereHas('messages')
            ->orderByDesc('last_active_at');

        $search = trim($this->historySearch);
        if ($search !== '') {
            $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%');
        }

        $rows = $query
            ->offset($this->historyPage * self::HISTORY_PAGE_SIZE)
            ->limit(self::HISTORY_PAGE_SIZE + 1)
            ->get();

        return [
            'rows' => $rows->take(self::HISTORY_PAGE_SIZE)->map(fn (AiConversation $c): array => [
                'id' => $c->getKey(),
                'title' => $c->title,
                'last_active' => $c->last_active_at?->diffForHumans() ?? $c->created_at->diffForHumans(),
            ])->all(),
            'has_more' => $rows->count() > self::HISTORY_PAGE_SIZE,
            'has_older' => $this->historyPage > 0,
        ];
    }

    public function toggleHistory(): void
    {
        $this->showHistory = ! $this->showHistory;
        $this->historyPage = 0;
    }

    public function olderHistory(): void
    {
        $this->historyPage++;
    }

    public function newerHistory(): void
    {
        $this->historyPage = max(0, $this->historyPage - 1);
    }

    /** Open a history thread — only one of THIS user's threads in THIS context. */
    public function openConversation(string $conversationId): void
    {
        $context = $this->context();

        $conversation = AiConversation::query()
            ->whereKey($conversationId)
            ->where('user_id', auth()->id())
            ->where('scope', $context->scope->value)
            ->where('workspace_id', $context->workspace?->getKey())
            ->where('project_id', $context->project?->getKey())
            ->first();

        if ($conversation === null) {
            return; // Not ours, not this context — silently ignored.
        }

        $this->loadConversation($conversation);
    }

    /** Load a persisted thread into the transcript, restoring its honest state. */
    protected function loadConversation(AiConversation $conversation): void
    {
        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::TRANSCRIPT_LIMIT)
            ->get();

        $this->activeConversationId = $conversation->getKey();
        $this->transcript = $messages->map(fn (AiMessage $m): array => $m->toTurn())->all();
        $this->showHistory = false;
        $this->error = null;
        $this->retryMessageId = null;

        $lastRouteRow = $messages->reverse()->first(fn (AiMessage $m): bool => $m->role === AiMessage::ROLE_ASSISTANT && $m->route !== null);
        $this->lastRoute = $lastRouteRow?->route;

        // A thread whose last turn never got an answer shows that honestly
        // after a refresh — with its retry affordance intact (§F24).
        $failedRow = $messages->last();
        if ($failedRow !== null && $failedRow->role === AiMessage::ROLE_USER && $failedRow->error !== null) {
            $this->error = $this->errorPayload(
                (string) ($failedRow->error['kind'] ?? 'unexpected'),
                $failedRow->error['technical'] ?? null,
            );
            $this->retryMessageId = $failedRow->getKey();
        }
    }

    /** Find or create the persisted thread for this user + context (§F11). */
    protected function ensureConversation(AiContext $context, string $firstText): AiConversation
    {
        if ($this->activeConversationId !== null) {
            $existing = AiConversation::query()
                ->whereKey($this->activeConversationId)
                ->where('user_id', auth()->id())
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $conversation = AiConversation::query()->create([
            'user_id' => auth()->id(),
            'scope' => $context->scope->value,
            'workspace_id' => $context->workspace?->getKey(),
            'project_id' => $context->project?->getKey(),
            'title' => AiConversation::titleFrom($firstText),
            'last_active_at' => now(),
        ]);

        $this->activeConversationId = $conversation->getKey();

        return $conversation;
    }

    // ── Action approvals (Phase J + §F6/§F7/§F19) ──────────────────────

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

    /** Decline a proposed action. Rejecting never executes anything. */
    public function rejectActionPlan(string $planId): void
    {
        $broker = new ActionBroker($this->context());
        $result = $broker->reject($planId, auth()->user());

        if ($result['ok'] ?? false) {
            $this->refreshActionCard($result['plan']);
        }
    }

    /** Replace a transcript card with the plan's current, honest state. */
    protected function refreshActionCard(ActionPlan $plan): void
    {
        $card = $plan->card();

        foreach ($this->transcript as $index => $turn) {
            foreach (($turn['actions'] ?? []) as $cardIndex => $existing) {
                if (($existing['plan_id'] ?? null) === $card['plan_id']) {
                    $this->transcript[$index]['actions'][$cardIndex] = $card;
                }
            }
        }

        // §F24 — an approved/rejected card's result must survive a refresh:
        // the persisted assistant row mirrors the plan's display state.
        if ($this->activeConversationId === null) {
            return;
        }

        AiMessage::query()
            ->where('ai_conversation_id', $this->activeConversationId)
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->each(function (AiMessage $row) use ($card): void {
                foreach (($row->actions ?? []) as $i => $existing) {
                    if (($existing['plan_id'] ?? null) === $card['plan_id']) {
                        $actions = $row->actions;
                        $actions[$i] = $card;
                        $row->forceFill(['actions' => $actions])->save();

                        return;
                    }
                }
            });
    }

    /**
     * May THIS user approve THIS proposed action? Drives the UI: a user who
     * cannot approve sees a disabled control with the reason — never an
     * enabled Approve button (§F19). The broker re-checks everything at the
     * click regardless; this is presentation honesty, not the boundary.
     */
    public function canApproveCard(array $card): bool
    {
        if (($card['status'] ?? null) !== ActionPlan::STATUS_PENDING) {
            return false;
        }

        $plan = ActionPlan::query()->find($card['plan_id'] ?? null);
        if ($plan === null || $plan->status !== ActionPlan::STATUS_PENDING || $plan->isExpired()) {
            return false;
        }

        $access = Access::for(auth()->user());
        $definition = ActionRegistry::definition($plan->action);
        if ($definition === null) {
            return false;
        }

        // Same shape as the broker's execution-time check (J.6) — ids, not
        // models — so the UI can never promise what the broker would refuse.
        return match ($plan->scope) {
            'project' => $plan->project_id !== null
                && $access->allows(Capability::AI_APPROVE_ACTIONS, 'project', null, $plan->project_id)
                && $access->allows($definition['capability'], 'project', null, $plan->project_id),
            'workspace' => $plan->workspace_id !== null
                && $access->allows(Capability::AI_APPROVE_ACTIONS, 'workspace', $plan->workspace_id)
                && $access->allows($definition['capability'], 'workspace', $plan->workspace_id),
            default => $access->allows(Capability::AI_APPROVE_ACTIONS, 'platform')
                && $access->allows($definition['capability'], 'platform'),
        };
    }

    // ── Example prompts (§F3) ──────────────────────────────────────────

    /**
     * Contextual starters for the welcome state. Each scope names what the
     * assistant can genuinely answer there with its tools — no generic fake
     * prompts (§F3).
     *
     * @return list<string>
     */
    public function examplePrompts(): array
    {
        return match ($this->context()->scope) {
            Scope::PROJECT => [
                __('ai.example_project_blocked'),
                __('ai.example_project_attention'),
                __('ai.example_project_warnings'),
                __('ai.example_next_step'),
            ],
            Scope::WORKSPACE => [
                __('ai.example_workspace_attention'),
                __('ai.example_workspace_activity'),
                __('ai.example_next_step'),
            ],
            default => [
                __('ai.example_platform_attention'),
                __('ai.example_platform_activity'),
                __('ai.example_platform_health'),
                __('ai.example_next_step'),
            ],
        };
    }

    /** A chip fills the composer and sends through the exact same path. */
    public function useExamplePrompt(string $prompt): void
    {
        $this->message = $prompt;
        $this->send();
    }

    // ── State ──────────────────────────────────────────────────────────

    protected ?bool $providerConfiguredCache = null;

    /** True when at least one provider is enabled (resolved once per request). */
    public function hasProvider(): bool
    {
        return $this->providerConfiguredCache ??= (new ModelRouter)->isConfigured();
    }

    /**
     * Model roles for the composer — product labels only (§F1): which model
     * id answers each role is operator detail, shown in the assistant's
     * Technical details and on the settings page, never in the chat face.
     * Labels need no routing queries — a role is a hint, not a resolution.
     *
     * @return list<array{role: string, label: string}>
     */
    public function roleOptions(): array
    {
        return array_map(
            fn (string $role): array => ['role' => $role, 'label' => ModelRouter::label($role)],
            ModelRouter::ROLES,
        );
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
