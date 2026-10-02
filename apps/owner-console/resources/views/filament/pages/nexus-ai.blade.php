{{--
    0.4.0 §15/§16/§28/§29 — Nexus AI conversation surface.

    What is real: scope-bound context, working conversations through the
    provider the operator configured, model-role routing, tool activity in
    product language, a permission-filtered tool inventory, and Inspect Mode
    (Phase I): a server-validated selected component with Explain / Diagnose
    actions and whitelisted appearance preferences behind an explicit
    preview + apply step.

    What is NOT here, stated plainly rather than implied: action tools and
    the approval flow (Phase J). This page cannot mutate anything but the
    acting user's own UI preferences.
--}}
<x-filament-panels::page>
    @php
        $tools = $this->availableTools();
        $providerConfigured = $this->hasProvider();
        $inspection = $this->inspection();
        $aiPrefs = $this->aiPreferences();
    @endphp

    {{-- Screen-reader announcements for Inspect Mode state changes (§I.12). --}}
    <span class="nx-visually-hidden" data-nx-inspect-live role="status" aria-live="polite"></span>

    {{-- Scope is ALWAYS visible (§16). Never inferred, never hidden. --}}
    <div class="nx-scope-banner nx-scope-banner--ai" data-nx-inspect="ai.scope_banner" data-nx-inspect-label="Context banner">
        <span class="nx-scope-banner__label">Context</span>
        <span class="nx-scope-banner__value">{{ $this->contextLabel() }}</span>
        <span class="nx-scope-banner__note">
            The assistant inherits your permissions and cannot see beyond this scope.
        </span>
    </div>

    {{-- Phase I: the selected component, resolved and authorised SERVER-SIDE.
         Everything shown here comes from the registry, never from the client. --}}
    <div data-nx-attach-root>
        @if ($inspection)
            <section class="nx-inspect-panel" aria-label="Selected component">
                <header class="nx-inspect-panel__head">
                    <span class="nx-inspect-panel__title">
                        <x-filament::icon icon="heroicon-o-eye" class="h-4 w-4" />
                        Selected component
                    </span>
                    <button type="button" class="nx-inspect-panel__remove" wire:click="clearInspectedComponent"
                            title="Detach this component from the conversation"
                            aria-label="Detach selected component">×</button>
                </header>

                <dl class="nx-inspect-panel__facts">
                    <div><dt>Selected</dt><dd>{{ $inspection->chipLabel() }}</dd></div>
                    <div><dt>Context</dt><dd>{{ $this->contextLabel() }}</dd></div>
                    <div><dt>Component</dt><dd><code class="nx-code">{{ $inspection->componentKey }}</code></dd></div>
                    <div><dt>Reads from</dt><dd>{{ $inspection->dataSource }}</dd></div>
                </dl>

                <p class="nx-inspect-panel__note">
                    The assistant treats this as context about what you are looking at — it will check the
                    real state with its read tools, not from the card itself.
                </p>

                @if ($providerConfigured)
                    <div class="nx-inspect-panel__actions">
                        <x-filament::button type="button" size="sm" color="gray" wire:click="explainComponent"
                                            icon="heroicon-o-question-mark-circle">
                            Explain this
                        </x-filament::button>
                        <x-filament::button type="button" size="sm" color="gray" wire:click="diagnoseComponent"
                                            icon="heroicon-o-wrench-screwdriver">
                            Diagnose this
                        </x-filament::button>
                    </div>
                @endif

                @php $choices = $this->uiChoices(); @endphp
                @if ($choices !== [])
                    <form wire:submit="previewUiAdjustment" class="nx-inspect-panel__appearance">
                        <label class="nx-inspect-panel__appearance-label" for="nx-ui-choice">
                            Appearance
                        </label>
                        <select id="nx-ui-choice" wire:model="uiFormChoice" aria-label="Choose an appearance change">
                            <option value="">Choose a change…</option>
                            @foreach ($choices as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <x-filament::button type="submit" size="sm" color="gray">
                            Preview
                        </x-filament::button>
                    </form>
                @endif

                {{-- Phase I §I.10: preview BEFORE apply. Nothing is persisted
                     until this card's Apply is clicked. --}}
                @if ($proposal = $this->uiProposal)
                    <div class="nx-inspect-proposal" role="group" aria-label="Proposed appearance change">
                        <strong class="nx-inspect-proposal__title">Proposed appearance change</strong>
                        <dl class="nx-inspect-panel__facts">
                            <div><dt>Component</dt><dd>{{ $proposal['component'] }}</dd></div>
                            <div>
                                <dt>Current</dt>
                                <dd>{{ $this->preferenceValueLabel($proposal['adjustment'], $proposal['current']) }}</dd>
                            </div>
                            <div>
                                <dt>Proposed</dt>
                                <dd>{{ $this->preferenceValueLabel($proposal['adjustment'], $proposal['value']) }}</dd>
                            </div>
                            <div><dt>Applies to</dt><dd>{{ $proposal['scope'] }}</dd></div>
                        </dl>
                        <div class="nx-inspect-proposal__actions">
                            <x-filament::button type="button" size="sm" wire:click="applyUiPreference">
                                Apply
                            </x-filament::button>
                            <x-filament::button type="button" size="sm" color="gray" wire:click="cancelUiPreference">
                                Cancel
                            </x-filament::button>
                        </div>
                    </div>
                @endif
            </section>
        @endif
    </div>

    @unless ($providerConfigured)
        <div class="nx-notice nx-notice--warning">
            <x-filament::icon icon="heroicon-o-sparkles" class="h-5 w-5" />
            <div>
                <strong>Nexus AI is not configured yet.</strong>
                <p>
                    Add a provider and model to enable conversations. The platform works fully
                    without AI; nothing else depends on it.
                </p>
                @if ($this->canConfigureAi())
                    <p class="nx-notice__action">Configure a provider under Settings → Nexus AI.</p>
                @endif
            </div>
        </div>
    @endunless

    {{-- Conversation --}}
    @if ($providerConfigured)
        <section class="nx-section @if (($aiPrefs['ai.transcript']['density'] ?? 'comfortable') !== 'comfortable') nx-density--{{ $aiPrefs['ai.transcript']['density'] }} @endif"
                 data-nx-inspect="ai.transcript" data-nx-inspect-label="Conversation">
            <div class="nx-chat" role="log" aria-live="polite" aria-label="Nexus AI conversation">
                @if ($transcript === [])
                    <div class="nx-empty nx-empty--inline">
                        <p class="nx-empty__body">
                            Ask a question below, or start from a quick action. Every answer is
                            grounded in this platform's own records at the scope shown above.
                        </p>
                    </div>
                @endif

                @foreach ($transcript as $turn)
                    <div @class(['nx-chat__turn', 'nx-chat__turn--user' => $turn['role'] === 'user', 'nx-chat__turn--ai' => $turn['role'] === 'assistant'])>
                        <span class="nx-chat__who">{{ $turn['role'] === 'user' ? 'You' : 'Nexus AI' }}</span>

                        {{-- Tool activity, humanised (§29). Never raw JSON. --}}
                        @if (! empty($turn['tools']))
                            <ul class="nx-chat__tools">
                                @foreach ($turn['tools'] as $activity)
                                    <li @class(['nx-tool-step', 'is-denied' => ! $activity['ok']])>
                                        <x-filament::icon
                                            icon="{{ $activity['ok'] ? 'heroicon-o-check' : 'heroicon-o-no-symbol' }}"
                                            class="h-3.5 w-3.5" />
                                        <span>
                                            @if ($activity['ok'])
                                                {{ $activity['label'] }}…
                                            @else
                                                Could not run <code class="nx-code">{{ $activity['tool'] }}</code>
                                                ({{ $activity['denied'] === 'missing_capability' ? 'not permitted' : ($activity['denied'] ?? 'unavailable') }})
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($turn['text'] !== '')
                            <p class="nx-chat__text">{!! nl2br(e($turn['text'])) !!}</p>
                        @endif

                        {{-- Phase J: proposed action plans. A card is a
                             permissioned proposal — nothing has executed and
                             nothing executes without a human click here. --}}
                        @foreach (($turn['actions'] ?? []) as $card)
                            <div class="nx-action-card" role="group" aria-label="Proposed action">
                                <header class="nx-action-card__head">
                                    <span class="nx-action-card__title">
                                        <x-filament::icon icon="heroicon-o-bolt" class="h-4 w-4" />
                                        Proposed action: {{ $card['action'] }}
                                    </span>
                                    <span @class(['nx-risk', 'nx-risk--'.$card['risk']])>
                                        {{ ucfirst($card['risk']) }} risk
                                    </span>
                                </header>

                                <p class="nx-action-card__intent">{{ $card['intent'] }}</p>

                                <dl class="nx-action-card__facts">
                                    <div>
                                        <dt>Affected</dt>
                                        <dd>
                                            @foreach ($card['affected'] as $affected)
                                                {{ $affected['resource'] }}@if(!empty($affected['project'])) · {{ $affected['project'] }}@endif@if(!empty($affected['id'])) · {{ $affected['id'] }}@endif
                                            @endforeach
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>What it does</dt>
                                        <dd>{{ $card['expected'] }}</dd>
                                    </div>
                                    <div>
                                        <dt>Plan</dt>
                                        <dd>
                                            <code class="nx-code">{{ \Illuminate\Support\Str::limit($card['plan_id'], 18) }}</code>
                                            @if ($card['expires_at'])
                                                · decision needed {{ \Illuminate\Support\Carbon::parse($card['expires_at'])->diffForHumans(parts: 1) }}
                                            @endif
                                        </dd>
                                    </div>
                                </dl>

                                @if ($card['status'] === 'pending')
                                    {{-- The plan id is a UUID (registry-validated), so it interpolates
                                         directly: @js does not compile inside component attributes. --}}
                                    <div class="nx-action-card__actions">
                                        <x-filament::button type="button" size="sm"
                                                            wire:click="approveActionPlan('{{ $card['plan_id'] }}')"
                                                            icon="heroicon-o-shield-check">
                                            Approve &amp; Apply
                                        </x-filament::button>
                                        <x-filament::button type="button" size="sm" color="gray"
                                                            wire:click="rejectActionPlan('{{ $card['plan_id'] }}')">
                                            Cancel
                                        </x-filament::button>
                                    </div>
                                @else
                                    <p @class(['nx-action-card__outcome', 'nx-action-card__outcome--'.$card['status']])>
                                        @if ($card['status'] === 'verified')
                                            Applied and verified — {{ $card['result']['verification']['detail'] ?? 'confirmed.' }}
                                        @elseif ($card['status'] === 'verification_failed')
                                            Applied, but verification could NOT confirm it — {{ $card['result']['verification']['detail'] ?? 'unconfirmed.' }}
                                        @elseif ($card['status'] === 'failed')
                                            The action failed: {{ $card['result']['error'] ?? 'an error occurred.' }} Nothing was changed.
                                        @elseif ($card['status'] === 'rejected')
                                            Declined — nothing was changed.
                                        @elseif ($card['status'] === 'expired')
                                            This plan expired before approval — nothing was changed.
                                        @elseif ($card['status'] === 'stale')
                                            The situation changed after this plan was made — refused for safety.
                                        @elseif ($card['status'] === 'approved')
                                            Approved — executing…
                                        @else
                                            {{ ucfirst($card['status']) }}.
                                        @endif
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            @if ($error)
                <div class="nx-notice nx-notice--warning" role="alert">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
                    <div><strong>{{ $error }}</strong></div>
                </div>
            @endif

            {{-- Composer --}}
            <form wire:submit="send" class="nx-composer">
                <label class="nx-visually-hidden" for="nexus-ai-message">Your question</label>
                <textarea
                    id="nexus-ai-message"
                    class="nx-composer__input"
                    rows="2"
                    placeholder="Ask about this {{ strtolower($this->context()->scope->label()) }}…"
                    wire:model="message"
                ></textarea>

                <div class="nx-composer__row">
                    <label class="nx-composer__role">
                        <span>Answer with</span>
                        <select wire:model="role" aria-label="Model role">
                            @foreach ($this->routingTable() as $route)
                                <option value="{{ $route['role'] }}">
                                    {{ $route['label'] }}@if ($route['model']) — {{ $route['model'] }}@endif
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <div class="nx-composer__actions">
                        @if ($transcript !== [])
                            <x-filament::button type="button" color="gray" size="sm" wire:click="clearConversation">
                                Clear
                            </x-filament::button>
                        @endif
                        <x-filament::button type="submit" icon="heroicon-o-paper-airplane">
                            Send
                        </x-filament::button>
                    </div>
                </div>
            </form>

            @if ($lastRoute && $lastRoute['model'])
                <p class="nx-section__description">
                    Last answer: {{ $lastRoute['provider'] }} · {{ $lastRoute['model'] }}
                </p>
            @endif
        </section>

        {{-- Quick actions --}}
        <section class="nx-section">
            <h2 class="nx-section__title">Quick actions</h2>
            <p class="nx-section__description">Convenience starters — they use the same tools and permissions listed below.</p>
            <div class="nx-grid nx-grid--actions">
                @foreach ($this->quickActions() as $action)
                    <button type="button" class="nx-action" wire:click="useQuickAction(@js($action['prompt']))">
                        <x-filament::icon icon="{{ $action['icon'] }}" class="h-4 w-4" />
                        <span>{{ $action['label'] }}</span>
                    </button>
                @endforeach
            </div>
        </section>
    @endif

    {{-- What the assistant may read, straight from the enforcing dispatcher. --}}
    @if ($aiPrefs['ai.tools']['visibility'] ?? true)
        <section class="nx-section" data-nx-inspect="ai.tools" data-nx-inspect-label="Available read tools">
        <h2 class="nx-section__title">What the assistant can read here</h2>
        <p class="nx-section__description">
            This list is produced by the permission-aware dispatcher: it contains exactly the
            tools <strong>{{ auth()->user()?->name }}</strong> may run at
            <strong>{{ $this->contextLabel() }}</strong>. Every call re-checks permission at
            execution time, so a revocation takes effect immediately.
        </p>

        @if ($tools === [])
            <div class="nx-empty nx-empty--inline">
                <p class="nx-empty__body">
                    No read tools are available to you at this scope. Your role may not include
                    access to the telemetry these tools read.
                </p>
            </div>
        @else
            <ul class="nx-tools">
                @foreach ($tools as $tool)
                    <li class="nx-tool">
                        <div class="nx-tool__head">
                            <x-filament::icon icon="heroicon-o-eye" class="h-4 w-4" />
                            <code class="nx-tool__name">{{ $tool['name'] }}</code>
                            <span class="nx-tag nx-tag--readonly">read-only</span>
                        </div>
                        <p class="nx-tool__description">{{ $tool['description'] }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    @endif

    {{-- §44: state plainly what is not built. --}}
    <details class="nx-advanced">
        <summary>Not enabled in this build</summary>
        <div class="nx-advanced__body">
            <div class="nx-fact">
                <span class="nx-fact__label">Inspect Mode</span>
                <span class="nx-fact__detail">
                    On — use the Inspect control in the toolbar to attach any highlighted component
                    to this conversation.
                </span>
            </div>
            <div class="nx-fact">
                <span class="nx-fact__label">Actions and approvals</span>
                <span class="nx-fact__detail">
                    Limited, permissioned actions exist: pause/resume Live Sync, create a backup,
                    re-run validation, retry one failed job, and record a cutover preflight. The
                    assistant can only PROPOSE them — every card above needs an explicit human
                    Approve &amp; Apply, re-authorised at the click. Arbitrary shell, arbitrary SQL,
                    restores, cutovers and credential changes are NOT available to the assistant at
                    all, and code changes never happen through this page.
                </span>
            </div>
            <div class="nx-fact">
                <span class="nx-fact__label">Model routing</span>
                <span class="nx-fact__detail">
                    A role is a hint, never a requirement. With one provider configured, every
                    role resolves to it.
                </span>
            </div>
        </div>
    </details>
</x-filament-panels::page>
