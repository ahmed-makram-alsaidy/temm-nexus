{{--
    0.6.0 Phase F — Nexus AI as a CHAT-FIRST assistant surface.

    The first viewport answers five questions (§5-second test):
      What context am I asking about?   → the context banner (product language)
      Where do I type?                  → the composer
      Is the assistant available?       → availability state (or not-configured card)
      What did it just do?              → humanised tool activity + grounding
      Does it need my approval?         → distinct proposed-action cards

    What changed in Phase F:
      - Chat + composer lead; the tool inventory moved behind "What can Nexus
        AI do here?" (§F1, §F4) — internal tool names live only inside ITS
        Technical details.
      - Durable conversations: refresh keeps the thread; a provider failure
        leaves an honest, retryable state, never a blank bubble (§F11, §F24).
      - Provider failures are CLASSIFIED product errors (§F8, §F10); raw
        statuses and provider text live only in Technical details.
      - Safe-action cards render in the viewer's language with Approve/Reject;
        a user who cannot approve never sees an enabled Approve (§F6, §F7, §F19).
      - Inspect Mode (Phase I) is unchanged and translated (§F16).

    What is NOT here, stated plainly rather than implied: the assistant cannot
    mutate anything without an explicit human approval card, and code changes
    never happen through this page.
--}}
<x-filament-panels::page>
    @php
        $providerConfigured = $this->hasProvider();
        $inspection = $this->inspection();
        $aiPrefs = $this->aiPreferences();
        $tools = $providerConfigured ? $this->availableTools() : [];
        $actions = $providerConfigured ? $this->availableActions() : [];
        $scopeLabel = $this->contextLabel();

        $humanTool = function (array $activity): string {
            $key = 'ai.tool_label_'.($activity['tool'] ?? '');
            $translated = __($key);

            return $translated === $key ? (string) ($activity['label'] ?? '') : $translated;
        };
        $humanDenialReason = fn (?string $reason): string => $reason === null
            ? __('ai.denied_generic')
            : __("ai.denied_{$reason}", []);
        $withFallback = function (string $key, string $fallback): string {
            $translated = __($key);

            return $translated === $key ? $fallback : $translated;
        };
    @endphp

    {{-- Screen-reader announcements for Inspect Mode state changes (§I.12). --}}
    <span class="nx-visually-hidden" data-nx-inspect-live role="status" aria-live="polite"></span>

    {{-- ── Header row: context + availability + history/new-thread actions ── --}}
    <div class="nx-ai-head">
        {{-- Scope is ALWAYS visible (§16), in product language (§F2). --}}
        <div class="nx-scope-banner nx-scope-banner--ai" data-nx-inspect="ai.scope_banner" data-nx-inspect-label="Context banner">
            <span class="nx-scope-banner__label">{{ __('ai.chat_context_label') }}</span>
            <span class="nx-scope-banner__value">{{ $scopeLabel }}</span>
            <span class="nx-scope-banner__note">{{ __('ai.scope_note') }}</span>
        </div>

        @if ($providerConfigured)
            <div class="nx-ai-head__actions">
                <button type="button" class="nx-btn nx-btn--quiet" wire:click="toggleHistory">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    {{ __('ai.recent_conversations') }}
                </button>
                <button type="button" class="nx-btn" wire:click="newConversation">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    {{ __('ai.new_conversation') }}
                </button>
            </div>
        @endif
    </div>

    {{-- ── Conversation history — rendered ONLY when opened (§F21) ────── --}}
    @if ($showHistory && $providerConfigured)
        @php $history = $this->conversations(); @endphp
        <section class="nx-ai-history" aria-label="{{ __('ai.recent_conversations') }}">
            <input
                type="search"
                class="nx-ai-history__search"
                placeholder="{{ __('ai.history_search_placeholder') }}"
                wire:model.debounce.400ms="historySearch"
                aria-label="{{ __('ai.history_search_placeholder') }}"
            />

            @if (($history['rows'] ?? []) === [])
                <p class="nx-ai-history__empty">{{ $historySearch === '' ? __('ai.history_empty') : __('ai.history_no_results') }}</p>
            @else
                <ul class="nx-ai-history__list">
                    @foreach ($history['rows'] as $conversation)
                        <li>
                            <button type="button" class="nx-ai-history__row" wire:click="openConversation('{{ $conversation['id'] }}')">
                                <span class="nx-ai-history__title">{{ $conversation['title'] }}</span>
                                <span class="nx-ai-history__when">{{ $conversation['last_active'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($history['has_older'] || $history['has_more'])
                <div class="nx-ai-history__pager">
                    @if ($history['has_older'])
                        <button type="button" class="nx-btn nx-btn--quiet" wire:click="newerHistory">{{ __('ai.history_newer') }}</button>
                    @endif
                    @if ($history['has_more'])
                        <button type="button" class="nx-btn nx-btn--quiet" wire:click="olderHistory">{{ __('ai.history_older') }}</button>
                    @endif
                </div>
            @endif
        </section>
    @endif

    {{-- ── Phase I: the selected component, resolved and authorised SERVER-SIDE.
         Everything shown here comes from the registry, never from the client. --}}
    <div data-nx-attach-root>
        @if ($inspection)
            <section class="nx-inspect-panel" aria-label="{{ __('ai.selected_component') }}">
                <header class="nx-inspect-panel__head">
                    <span class="nx-inspect-panel__title">
                        <x-filament::icon icon="heroicon-o-eye" class="h-4 w-4" />
                        {{ __('ai.selected_component') }}
                    </span>
                    <button type="button" class="nx-inspect-panel__remove" wire:click="clearInspectedComponent"
                            title="{{ __('ai.cancel') }} — {{ __('ai.selected_component') }}"
                            aria-label="{{ __('ai.selected_component') }}">×</button>
                </header>

                <dl class="nx-inspect-panel__facts">
                    <div><dt>{{ __('ai.selected') }}</dt><dd>{{ $inspection->chipLabel() }}</dd></div>
                    <div><dt>{{ __('ai.chat_context_label') }}</dt><dd>{{ $scopeLabel }}</dd></div>
                    <div><dt>{{ __('ai.component') }}</dt><dd><code class="nx-code">{{ $inspection->componentKey }}</code></dd></div>
                    <div><dt>{{ __('ai.reads_from') }}</dt><dd>{{ $inspection->dataSource }}</dd></div>
                </dl>

                <p class="nx-inspect-panel__note">
                    {{ __('ai.inspect_note') }}
                </p>

                @if ($providerConfigured)
                    <div class="nx-inspect-panel__actions">
                        <x-filament::button type="button" size="sm" color="gray" wire:click="explainComponent"
                                            icon="heroicon-o-question-mark-circle">
                            {{ __('ai.explain_this') }}
                        </x-filament::button>
                        <x-filament::button type="button" size="sm" color="gray" wire:click="diagnoseComponent"
                                            icon="heroicon-o-wrench-screwdriver">
                            {{ __('ai.diagnose_this') }}
                        </x-filament::button>
                    </div>
                @endif

                @php $choices = $this->uiChoices(); @endphp
                @if ($choices !== [])
                    <form wire:submit="previewUiAdjustment" class="nx-inspect-panel__appearance">
                        <label class="nx-inspect-panel__appearance-label" for="nx-ui-choice">
                            {{ __('ai.appearance') }}
                        </label>
                        <select id="nx-ui-choice" wire:model="uiFormChoice" aria-label="{{ __('ai.appearance') }}">
                            <option value="">{{ __('ai.choose_a_change') }}</option>
                            @foreach ($choices as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <x-filament::button type="submit" size="sm" color="gray">
                            {{ __('ai.preview') }}
                        </x-filament::button>
                    </form>
                @endif

                {{-- Phase I §I.10: preview BEFORE apply. Nothing is persisted
                     until this card's Apply is clicked. --}}
                @if ($proposal = $this->uiProposal)
                    <div class="nx-inspect-proposal" role="group" aria-label="{{ __('ai.proposed_appearance_change') }}">
                        <strong class="nx-inspect-proposal__title">{{ __('ai.proposed_appearance_change') }}</strong>
                        <dl class="nx-inspect-panel__facts">
                            <div><dt>{{ __('ai.component') }}</dt><dd>{{ $proposal['component'] }}</dd></div>
                            <div>
                                <dt>{{ __('ai.current') }}</dt>
                                <dd>{{ $this->preferenceValueLabel($proposal['adjustment'], $proposal['current']) }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('ai.proposed') }}</dt>
                                <dd>{{ $this->preferenceValueLabel($proposal['adjustment'], $proposal['value']) }}</dd>
                            </div>
                            <div><dt>{{ __('ai.applies_to') }}</dt><dd>{{ $proposal['scope'] }}</dd></div>
                        </dl>
                        <div class="nx-inspect-proposal__actions">
                            <x-filament::button type="button" size="sm" wire:click="applyUiPreference">
                                {{ __('ai.apply') }}
                            </x-filament::button>
                            <x-filament::button type="button" size="sm" color="gray" wire:click="cancelUiPreference">
                                {{ __('ai.cancel') }}
                            </x-filament::button>
                        </div>
                    </div>
                @endif
            </section>
        @endif
    </div>

    {{-- ── Provider not configured (§F9): a quiet, honest card — no dead end. ── --}}
    @unless ($providerConfigured)
        <div class="nx-notice nx-notice--warning">
            <x-filament::icon icon="heroicon-o-sparkles" class="h-5 w-5" />
            <div>
                <strong>{{ __('ai.not_configured_yet') }}</strong>
                <p>
                    {{ __('ai.not_configured_body') }}
                </p>
                @if ($this->canConfigureAi())
                    <p class="nx-notice__action">
                        <a class="nx-btn" href="{{ \App\Filament\Pages\NexusAiSettings::getUrl() }}">{{ __('ai.not_configured_admin_cta') }}</a>
                    </p>
                @endif
            </div>
        </div>
    @endunless

    {{-- ── THE CONVERSATION — the primary surface (§F1) ────────────────── --}}
    @if ($providerConfigured)
        <section class="nx-section nx-ai-main @if (($aiPrefs['ai.transcript']['density'] ?? 'comfortable') !== 'comfortable') nx-density--{{ $aiPrefs['ai.transcript']['density'] }} @endif"
                 data-nx-inspect="ai.transcript" data-nx-inspect-label="Conversation">
            <div class="nx-chat" role="log" aria-live="polite" aria-label="Nexus AI conversation">
                @if ($transcript === [])
                    {{-- Welcome state (§F3): calm, contextual example prompts. --}}
                    <div class="nx-welcome">
                        <span class="nx-welcome__glyph" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" width="22" height="22"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" /></svg>
                        </span>
                        <p class="nx-welcome__title">{{ __('ai.welcome_title') }}</p>
                        <p class="nx-welcome__body">{{ __('ai.welcome_body') }}</p>
                        <div class="nx-welcome__prompts">
                            @foreach ($this->examplePrompts() as $prompt)
                                <button type="button" class="nx-prompt-chip" wire:click="useExamplePrompt(@js($prompt))">
                                    {{ $prompt }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @foreach ($transcript as $turn)
                    <div @class(['nx-chat__turn', 'nx-chat__turn--user' => $turn['role'] === 'user', 'nx-chat__turn--ai' => $turn['role'] === 'assistant'])>
                        <span class="nx-chat__who">{{ $turn['role'] === 'user' ? __('ai.you') : __('ai.title') }}</span>

                        {{-- Tool activity, humanised (§29, §F5). Never raw JSON,
                             never internal tool names on the default face. --}}
                        @if (! empty($turn['tools']))
                            <ul class="nx-chat__tools">
                                @foreach ($turn['tools'] as $activity)
                                    <li @class(['nx-tool-step', 'is-denied' => ! $activity['ok']])>
                                        <x-filament::icon
                                            icon="{{ $activity['ok'] ? 'heroicon-o-check' : 'heroicon-o-no-symbol' }}"
                                            class="h-3.5 w-3.5" />
                                        <span>
                                            @if ($activity['ok'])
                                                {{ $humanTool($activity) }}…
                                            @else
                                                {{ __('ai.tool_activity_denied', ['label' => $humanTool($activity), 'reason' => $humanDenialReason($activity['denied'] ?? null)]) }}
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($turn['text'] !== '')
                            <div class="nx-chat__text nx-prose">{!! \App\Support\ChatMarkdown::toHtml($turn['text']) !!}</div>

                            {{-- Grounding (§F15): which platform data the answer used. --}}
                            @if ($turn['role'] === 'assistant')
                                @php $groundedOn = collect($turn['tools'] ?? [])->filter(fn ($a) => $a['ok'] ?? false)->map($humanTool)->unique()->values(); @endphp
                                @if ($groundedOn->isNotEmpty())
                                    <p class="nx-grounding">
                                        <span>{{ __('ai.based_on') }}</span>
                                        {{ $groundedOn->implode(' · ') }}
                                    </p>
                                @endif
                            @endif
                        @endif

                        {{-- Phase J + §F6/§F7/§F19: proposed action plans. A card
                             is a permissioned proposal — nothing has executed and
                             nothing executes without a human click here. --}}
                        @foreach (($turn['actions'] ?? []) as $card)
                            @php
                                $actionKey = $card['action'] ?? '';
                                $actionTitle = $withFallback("ai.action_label_{$actionKey}", (string) $actionKey);
                                $actionEffect = $withFallback("ai.action_effect_{$actionKey}", (string) ($card['expected'] ?? ''));
                                $actionIntent = $withFallback("ai.action_intent_{$actionKey}", (string) ($card['intent'] ?? ''));
                                $projectName = collect($card['affected'] ?? [])->first(fn ($a) => ! empty($a['project']))['project'] ?? null;
                                $mayApprove = $this->canApproveCard($card);
                                $riskLabel = $withFallback("ai.risk_{$card['risk']}", ucfirst((string) $card['risk']));
                            @endphp
                            <div class="nx-action-card" role="group" aria-label="{{ __('ai.proposed_action') }}">
                                <header class="nx-action-card__head">
                                    <span class="nx-action-card__title">
                                        <x-filament::icon icon="heroicon-o-bolt" class="h-4 w-4" />
                                        {{ __('ai.proposed_action') }} — {{ $actionTitle }}
                                    </span>
                                    <span @class(['nx-risk', 'nx-risk--'.$card['risk']])>
                                        {{ $riskLabel }}
                                    </span>
                                </header>

                                <p class="nx-action-card__intent">
                                    @if ($projectName !== null)
                                        {{ str_replace(':project', $projectName, $actionIntent) }}
                                    @else
                                        {{ $actionIntent }}
                                    @endif
                                </p>

                                @if (! empty($card['arguments']['reason']))
                                    <p class="nx-action-card__reason">{{ __('ai.reason_line', ['reason' => $card['arguments']['reason']]) }}</p>
                                @endif

                                <dl class="nx-action-card__facts">
                                    <div>
                                        <dt>{{ __('ai.affected') }}</dt>
                                        <dd>
                                            @foreach ($card['affected'] ?? [] as $affected)
                                                @php
                                                    $resourceKey = "ai.action_resource_{$affected['resource']}";
                                                    $resourceLabel = $withFallback($resourceKey, (string) $affected['resource']);
                                                @endphp
                                                {{ $resourceLabel }}@if(!empty($affected['project'])) · {{ $affected['project'] }}@endif
                                            @endforeach
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>{{ __('ai.what_it_does') }}</dt>
                                        <dd>{{ $actionEffect }}</dd>
                                    </div>
                                </dl>

                                @if (($card['arguments']['uuid'] ?? '') !== '')
                                    <p class="nx-action-card__reason">
                                        <span class="nx-visually-hidden">{{ __('ai.action_resource_queue_job') }}:</span>
                                        <code class="nx-code" dir="ltr">{{ \Illuminate\Support\Str::limit($card['arguments']['uuid'], 22) }}</code>
                                    </p>
                                @endif

                                @if ($card['status'] === 'pending')
                                    {{-- §F19: an approver re-authorised at the click; a
                                         reviewer without the capability sees a disabled
                                         control with the reason, never an enabled Approve. --}}
                                    <div class="nx-action-card__actions">
                                        @if ($mayApprove)
                                            <x-filament::button type="button" size="sm"
                                                                wire:click="approveActionPlan('{{ $card['plan_id'] }}')"
                                                                icon="heroicon-o-shield-check">
                                                {{ __('ai.approve') }}
                                            </x-filament::button>
                                        @else
                                            <x-filament::button type="button" size="sm" disabled icon="heroicon-o-shield-check"
                                                                title="{{ __('ai.approve_disabled_reason') }}">
                                                {{ __('ai.approve') }}
                                            </x-filament::button>
                                            <p class="nx-action-card__reason">{{ __('ai.approve_disabled_reason') }}</p>
                                        @endif
                                        <x-filament::button type="button" size="sm" color="gray"
                                                            wire:click="rejectActionPlan('{{ $card['plan_id'] }}')">
                                            {{ __('ai.reject') }}
                                        </x-filament::button>
                                    </div>

                                    <x-nx.details class="nx-action-card__tech" summary="{{ __('foundation.technical_details') }}">
                                        <div class="nx-fact">
                                            <span class="nx-fact__label">{{ __('ai.plan') }}</span>
                                            <code class="nx-code" dir="ltr">{{ $card['plan_id'] }}</code>
                                        </div>
                                        @if ($card['expires_at'])
                                            <div class="nx-fact">
                                                <span class="nx-fact__label">{{ __('ai.plan_expires', ['when' => \Illuminate\Support\Carbon::parse($card['expires_at'])->diffForHumans(parts: 1)]) }}</span>
                                            </div>
                                        @endif
                                    </x-nx.details>
                                @else
                                    <p @class(['nx-action-card__outcome', 'nx-action-card__outcome--'.$card['status']])>
                                        @php $detail = (string) ($card['result']['verification']['detail'] ?? $card['result']['detail'] ?? ''); @endphp
                                        @if ($card['status'] === 'verified')
                                            {{ __('ai.action_outcome_verified', ['detail' => $detail !== '' ? $detail : __('ai.action_outcome_generic', ['status' => 'confirmed'])]) }}
                                        @elseif ($card['status'] === 'verification_failed')
                                            {{ __('ai.action_outcome_verification_failed', ['detail' => $detail !== '' ? $detail : '—']) }}
                                        @elseif ($card['status'] === 'failed')
                                            {{ __('ai.action_outcome_failed') }}
                                        @elseif ($card['status'] === 'rejected')
                                            {{ __('ai.action_outcome_rejected') }}
                                        @elseif ($card['status'] === 'expired')
                                            {{ __('ai.action_outcome_expired') }}
                                        @elseif ($card['status'] === 'stale')
                                            {{ __('ai.action_outcome_stale') }}
                                        @elseif ($card['status'] === 'approved')
                                            {{ __('ai.action_outcome_approved') }}
                                        @else
                                            {{ __('ai.action_outcome_generic', ['status' => ucfirst($card['status'])]) }}
                                        @endif
                                    </p>

                                    @if ($card['status'] === 'failed' && filled($card['result']['error'] ?? null))
                                        <x-nx.details class="nx-action-card__tech" summary="{{ __('foundation.technical_details') }}">
                                            <code class="nx-code" dir="ltr">{{ \Illuminate\Support\Str::limit((string) $card['result']['error'], 200) }}</code>
                                        </x-nx.details>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            {{-- The classified error card (§F8, §F10) — what failed / what it
                 means / what to do / Retry / Technical details ▸ --}}
            @if ($error)
                <x-nx.error
                    :payload="['title' => $error['title'], 'body' => $error['body'], 'technical' => $error['technical']]"
                    :retry="$error['retryable'] ? 'retryFailedTurn' : null"
                />
            @endif

            {{-- Composer (§F1): the primary input. No provider metadata. --}}
            <form wire:submit="send" class="nx-composer">
                <label class="nx-visually-hidden" for="nexus-ai-message">{{ __('ai.your_question') }}</label>
                <textarea
                    id="nexus-ai-message"
                    class="nx-composer__input"
                    rows="2"
                    placeholder="{{ __('ai.composer_placeholder') }}"
                    wire:model="message"
                ></textarea>

                <div class="nx-composer__row">
                    <label class="nx-composer__role">
                        <span>{{ __('ai.answer_with') }}</span>
                        <select wire:model="role" aria-label="{{ __('ai.answer_with') }}">
                            @foreach ($this->roleOptions() as $option)
                                <option value="{{ $option['role'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    <div class="nx-composer__actions">
                        <span class="nx-thinking" wire:loading wire:target="send, retryFailedTurn">
                            <span class="nx-thinking__dot" aria-hidden="true"></span>
                            {{ __('ai.thinking') }}
                        </span>
                        <x-filament::button type="submit" icon="heroicon-o-paper-airplane"
                                            wire:loading.attr="disabled" wire:target="send, retryFailedTurn">
                            {{ __('ai.send') }}
                        </x-filament::button>
                    </div>
                </div>
            </form>
        </section>

        {{-- ── What the assistant may do here — BEHIND the disclosure (§F4).
             Human capability + read-only/action badge + one sentence. Internal
             tool names live only inside the nested Technical details. ── --}}
        @if ($aiPrefs['ai.tools']['visibility'] ?? true)
            <section class="nx-section" data-nx-inspect="ai.tools" data-nx-inspect-label="Available read tools">
                <x-nx.details summary="{{ __('ai.capabilities_title') }}">
                    <p class="nx-section__description">{{ __('ai.capabilities_intro') }}</p>

                    @if ($tools === [] && $actions === [])
                        <div class="nx-empty nx-empty--inline">
                            <p class="nx-empty__body">
                                {{ __('ai.capability_empty') }}
                            </p>
                        </div>
                    @else
                        <ul class="nx-capabilities">
                            @foreach ($tools as $tool)
                                @php
                                    $labelKey = "ai.tool_label_{$tool['name']}";
                                    $capabilityLabel = __($labelKey);
                                    if ($capabilityLabel === $labelKey) {
                                        $capabilityLabel = $tool['label'];
                                    }
                                    $descKey = "ai.tool_desc_{$tool['name']}";
                                    $capabilityDesc = __($descKey);
                                    if ($capabilityDesc === $descKey) {
                                        $capabilityDesc = $tool['description'];
                                    }
                                @endphp
                                <li class="nx-capability">
                                    <div class="nx-capability__head">
                                        <x-filament::icon icon="heroicon-o-eye" class="h-4 w-4" />
                                        <span class="nx-capability__name">{{ $capabilityLabel }}</span>
                                        <span class="nx-tag nx-tag--readonly">{{ __('ai.badge_read_only') }}</span>
                                    </div>
                                    <p class="nx-capability__description">{{ $capabilityDesc }}</p>
                                </li>
                            @endforeach

                            @foreach ($actions as $action)
                                <li class="nx-capability nx-capability--action">
                                    <div class="nx-capability__head">
                                        <x-filament::icon icon="heroicon-o-bolt" class="h-4 w-4" />
                                        <span class="nx-capability__name">{{ __("ai.action_label_{$action['name']}") }}</span>
                                        <span class="nx-tag nx-tag--action">{{ __('ai.badge_action') }}</span>
                                    </div>
                                    <p class="nx-capability__description">{{ __("ai.action_effect_{$action['name']}") }}</p>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <x-nx.details summary="{{ __('foundation.technical_details') }}">
                        <ul class="nx-technical-tools">
                            @foreach ($tools as $tool)
                                <li><code class="nx-code" dir="ltr">{{ $tool['name'] }}</code></li>
                            @endforeach
                            @foreach ($actions as $action)
                                <li><code class="nx-code" dir="ltr">{{ $action['name'] }}</code></li>
                            @endforeach
                        </ul>
                    </x-nx.details>
                </x-nx.details>
            </section>
        @endif

        {{-- §44: state plainly what is not built. --}}
        <details class="nx-advanced">
            <summary>{{ __('ai.not_enabled_in_this_build') }}</summary>
            <div class="nx-advanced__body">
                <div class="nx-fact">
                    <span class="nx-fact__label">{{ __('ai.inspect_mode') }}</span>
                    <span class="nx-fact__detail">{{ __('ai.not_enabled_inspect_detail') }}</span>
                </div>
                <div class="nx-fact">
                    <span class="nx-fact__label">{{ __('ai.actions_and_approvals') }}</span>
                    <span class="nx-fact__detail">{{ __('ai.not_enabled_actions_detail') }}</span>
                </div>
                <div class="nx-fact">
                    <span class="nx-fact__label">{{ __('ai.model_routing') }}</span>
                    <span class="nx-fact__detail">{{ __('ai.not_enabled_routing_detail') }}</span>
                </div>
            </div>
        </details>
    @endif
</x-filament-panels::page>
