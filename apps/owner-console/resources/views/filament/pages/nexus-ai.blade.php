{{--
    0.4.0 §15/§16/§28/§29 — Nexus AI conversation surface.

    What is real: scope-bound context, working conversations through the
    provider the operator configured, model-role routing, tool activity in
    product language, and a permission-filtered tool inventory.

    What is NOT here, stated plainly rather than implied: Inspect Mode and the
    action/approval flow. This page is read-only.
--}}
<x-filament-panels::page>
    @php
        $tools = $this->availableTools();
        $providerConfigured = $this->hasProvider();
    @endphp

    {{-- Scope is ALWAYS visible (§16). Never inferred, never hidden. --}}
    <div class="nx-scope-banner nx-scope-banner--ai">
        <span class="nx-scope-banner__label">Context</span>
        <span class="nx-scope-banner__value">{{ $this->contextLabel() }}</span>
        <span class="nx-scope-banner__note">
            The assistant inherits your permissions and cannot see beyond this scope.
        </span>
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
        <section class="nx-section">
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
    <section class="nx-section">
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

    {{-- §44: state plainly what is not built. --}}
    <details class="nx-advanced">
        <summary>Not enabled in this build</summary>
        <div class="nx-advanced__body">
            <div class="nx-fact">
                <span class="nx-fact__label">Inspect Mode</span>
                <span class="nx-fact__detail">
                    Selecting a UI component to attach it to the conversation is not built yet.
                </span>
            </div>
            <div class="nx-fact">
                <span class="nx-fact__label">Actions and approvals</span>
                <span class="nx-fact__detail">
                    The assistant is read-only. It cannot change anything, and no action tool is
                    reachable from this page.
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
