{{--
    0.4.0 §15/§16/§28/§29 — Nexus AI global surface.

    HONEST SCOPE: this renders the persistent entry point, the route-derived
    context scope, the permission-filtered read-tool inventory, and the quick
    actions. It does NOT run a conversation yet. Nothing here mutates anything.
--}}
<x-filament-panels::page>
    @php
        $context = $this->context();
        $tools = $this->availableTools();
    @endphp

    {{-- Scope is ALWAYS visible (§16). Never inferred, never hidden. --}}
    <div class="nx-scope-banner nx-scope-banner--ai">
        <span class="nx-scope-banner__label">Context</span>
        <span class="nx-scope-banner__value">{{ $this->contextLabel() }}</span>
        <span class="nx-scope-banner__note">
            The assistant inherits your permissions and cannot see beyond this scope.
        </span>
    </div>

    @unless ($this->hasProvider())
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

    <section class="nx-section">
        <h2 class="nx-section__title">Quick actions</h2>
        <p class="nx-section__description">Convenience starters — they use the same tools and permissions listed below.</p>
        <div class="nx-grid nx-grid--actions">
            @foreach ($this->quickActions() as $action)
                <button type="button" class="nx-action" disabled
                        title="Conversation is not enabled in this build.">
                    <x-filament::icon icon="{{ $action['icon'] }}" class="h-4 w-4" />
                    <span>{{ $action['label'] }}</span>
                </button>
            @endforeach
        </div>
    </section>

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

    {{-- §44: state plainly what is not built, rather than implying it is. --}}
    <section class="nx-section">
        <h2 class="nx-section__title">Not enabled in this build</h2>
        <ul class="nx-limitations">
            <li>Conversations with a provider (streaming, history, model routing).</li>
            <li>Executing the tools above against live telemetry.</li>
            <li>Inspect Mode component selection.</li>
            <li>Action tools and the approval flow for any change.</li>
        </ul>
        <p class="nx-section__description">
            These are tracked as Phases G–J of the 0.4.0 plan. Until they land, this page is a
            scaffold: it shows the scope model and the permission boundary, and it cannot
            change anything.
        </p>
    </section>
</x-filament-panels::page>
