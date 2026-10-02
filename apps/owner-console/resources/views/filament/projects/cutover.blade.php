{{--
    0.4.0 Phase E (§10) — the CUTOVER readiness experience.

    Composition, in the order the mission specifies:
      overall readiness  ->  blocking issues (with WHY)  ->  the gates  ->
      Live Sync / lag  ->  reconciliation + validation  ->  backup  ->
      rollback readiness  ->  final sync readiness  ->  human approvals  ->
      the ordered plan.

    Safety: the primary action is disabled until every gate passes, and this
    page never executes a DNS/endpoint change. Those remain operator-owned.
--}}
@php
    $dangerCount = count(array_filter($issues, fn ($i) => $i['severity'] === 'danger'));
    $warningCount = count(array_filter($issues, fn ($i) => $i['severity'] === 'warning'));
    // Phase I — this user's appearance preferences for the cutover components.
    $ui = $ui;
@endphp

<div class="nx-stack">

    {{-- 1. Overall readiness: the single answer. --}}
    <header
        class="nx-overview-head @if (($ui['cutover.overall']['density'] ?? 'comfortable') !== 'comfortable') nx-density--{{ $ui['cutover.overall']['density'] }} @endif"
        data-nx-inspect="cutover.overall"
        data-nx-inspect-label="Overall readiness"
    >
        <div class="nx-overview-head__progress">
            <span class="nx-fact__label">Overall readiness</span>
            <span @class(['nx-cutover-state', 'nx-cutover-state--'.$overall['tone']])>
                <x-filament::icon
                    icon="{{ $overall['tone'] === 'success' ? 'heroicon-o-check-badge' : ($overall['tone'] === 'danger' ? 'heroicon-o-no-symbol' : 'heroicon-o-exclamation-triangle') }}"
                    class="h-6 w-6" />
                {{ $overall['label'] }}
            </span>
            <span class="nx-fact__detail">{{ $overall['detail'] }}</span>
        </div>

        <div class="nx-overview-head__facts">
            <div class="nx-fact">
                <span class="nx-fact__label">Blocking</span>
                <span class="nx-fact__value nx-num">{{ $dangerCount }}</span>
                <span class="nx-fact__detail">gates stopping the switch</span>
            </div>
            <div class="nx-fact">
                <span class="nx-fact__label">To verify</span>
                <span class="nx-fact__value nx-num">{{ $warningCount }}</span>
                <span class="nx-fact__detail">warnings and unverified gates</span>
            </div>
            <div class="nx-fact">
                <span class="nx-fact__label">Approvals outstanding</span>
                <span class="nx-fact__value nx-num">{{ $r->pendingApprovalCount() }}</span>
                <span class="nx-fact__detail">of {{ count($approvals) }} required</span>
            </div>
            <div class="nx-fact">
                <span class="nx-fact__label">Plan</span>
                <span class="nx-fact__value">{{ $plan ? 'Recorded' : 'Not created' }}</span>
                <span class="nx-fact__detail">
                    {{ $plan ? $plan->run_id : 'Run preflight to record one' }}
                </span>
            </div>
        </div>
    </header>

    {{-- The primary action is explicit about why it is disabled. --}}
    <div class="nx-cutover-action">
        @if ($overall['state'] === 'READY')
            <x-filament::button
                tag="a"
                color="success"
                icon="heroicon-o-arrow-right"
                href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('migration-center', ['record' => $project]) }}"
            >
                Begin the cutover window
            </x-filament::button>
            <span class="nx-fact__detail">
                The platform will not change DNS or endpoints. The ordered plan below is for an
                operator to execute and record.
            </span>
        @else
            <x-filament::button color="gray" disabled icon="heroicon-o-lock-closed">
                Cutover is not available yet
            </x-filament::button>
            <span class="nx-fact__detail">
                {{ $overall['reason'] ?? 'Resolve the items below first.' }}
            </span>
        @endif
    </div>

    {{-- 2. Blocking issues — WHY, never just "blocked". --}}
    @if ($issues !== [])
        <section class="nx-section">
            <h2 class="nx-section__title">Why you cannot proceed</h2>
            <ul class="nx-attention">
                @foreach ($issues as $issue)
                    <li @class([
                        'nx-attention__item',
                        'nx-attention__item--danger' => $issue['severity'] === 'danger',
                        'nx-attention__item--warning' => $issue['severity'] === 'warning',
                    ])>
                        <x-filament::icon
                            icon="{{ $issue['severity'] === 'danger' ? 'heroicon-o-no-symbol' : 'heroicon-o-exclamation-triangle' }}"
                            class="h-4 w-4" />
                        <div>
                            <strong>{{ $issue['title'] }}</strong>
                            <p>{{ $issue['detail'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- 3. Every gate, with its evidence. --}}
    @if ($ui['cutover.gates']['visibility'] ?? true)
        <section
            class="nx-section @if (($ui['cutover.gates']['density'] ?? 'comfortable') !== 'comfortable') nx-density--{{ $ui['cutover.gates']['density'] }} @endif"
            data-nx-inspect="cutover.gates"
            data-nx-inspect-label="Readiness gates"
        >
            <h2 class="nx-section__title">Readiness gates</h2>
        <p class="nx-section__description">
            A gate with no evidence reads “Not verified” rather than green. The platform never
            guesses readiness.
        </p>
        <ul class="nx-gates">
            @foreach ($gates as $gate)
                <li @class(['nx-gate', 'nx-gate--'.$gate['tone']])>
                    <x-filament::icon :icon="$gate['icon']" class="h-4 w-4" />
                    <div class="nx-gate__body">
                        <span class="nx-gate__section">{{ $gate['section'] }}</span>
                        <span class="nx-gate__evidence">{{ $gate['evidence'] }}</span>
                        @if ($gate['detail'])
                            <span class="nx-gate__detail">{{ $gate['detail'] }}</span>
                        @endif
                    </div>
                    <span class="nx-gate__state">{{ $gate['label'] }}</span>
                </li>
            @endforeach
        </ul>
        </section>
    @endif

    {{-- 4. The six named readiness dimensions. --}}
    <section class="nx-section">
        <h2 class="nx-section__title">Readiness detail</h2>
        <div class="nx-grid nx-grid--stats">
            <div class="nx-stat-card">
                <span class="nx-stat-card__label">Live Sync</span>
                <span class="nx-stat-card__value nx-stat-card__value--{{ $liveSync['tone'] === 'neutral' ? 'warning' : $liveSync['tone'] }}">
                    {{ $liveSync['label'] }}
                </span>
                <span class="nx-stat-card__hint">{{ $liveSync['detail'] }}</span>
            </div>
            <div class="nx-stat-card">
                <span class="nx-stat-card__label">Reconciliation</span>
                <span class="nx-stat-card__value nx-stat-card__value--{{ $validation['tone'] === 'warning' ? 'warning' : ($validation['tone'] === 'danger' ? 'danger' : 'success') }}">
                    {{ $validation['label'] }}
                </span>
                <span class="nx-stat-card__hint">{{ $validation['detail'] }}</span>
            </div>
            <div class="nx-stat-card">
                <span class="nx-stat-card__label">Backup</span>
                <span class="nx-stat-card__value nx-stat-card__value--{{ $backup['tone'] === 'success' ? 'success' : 'danger' }}">
                    {{ $backup['label'] }}
                </span>
                <span class="nx-stat-card__hint">{{ $backup['detail'] }}</span>
            </div>
            <div class="nx-stat-card">
                <span class="nx-stat-card__label">Rollback</span>
                <span class="nx-stat-card__value nx-stat-card__value--{{ $rollback['ready'] ? 'success' : 'warning' }}">
                    {{ $rollback['label'] }}
                </span>
                <span class="nx-stat-card__hint">{{ $rollback['detail'] }}</span>
            </div>
            <div class="nx-stat-card">
                <span class="nx-stat-card__label">Final sync</span>
                <span class="nx-stat-card__value nx-stat-card__value--{{ $finalSync['tone'] === 'success' ? 'success' : ($finalSync['tone'] === 'danger' ? 'danger' : 'warning') }}">
                    {{ $finalSync['label'] }}
                </span>
                <span class="nx-stat-card__hint">{{ $finalSync['detail'] }}</span>
            </div>
        </div>
    </section>

    {{-- 5. Human approvals. Nothing auto-approves. --}}
    @if ($ui['cutover.approvals']['visibility'] ?? true)
        <section class="nx-section" data-nx-inspect="cutover.approvals" data-nx-inspect-label="Human approvals">
            <h2 class="nx-section__title">Human approvals</h2>
        <p class="nx-section__description">
            Every production-affecting gate needs an explicit decision. A gate with no record is
            awaiting, never granted.
        </p>
        <ul class="nx-approvals">
            @foreach ($approvals as $approval)
                <li @class([
                    'nx-approval',
                    'nx-approval--approved' => $approval['decision'] === 'approved',
                    'nx-approval--rejected' => $approval['decision'] === 'rejected',
                ])>
                    <x-filament::icon
                        icon="{{ $approval['decision'] === 'approved' ? 'heroicon-o-check-circle' : ($approval['decision'] === 'rejected' ? 'heroicon-o-x-circle' : 'heroicon-o-clock') }}"
                        class="h-4 w-4" />
                    <div>
                        <strong>{{ $approval['label'] }}</strong>
                        <p>
                            @if ($approval['decision'] === 'approved')
                                Approved{{ $approval['decided_by'] ? ' by '.$approval['decided_by'] : '' }}
                            @elseif ($approval['decision'] === 'rejected')
                                Rejected{{ $approval['decided_by'] ? ' by '.$approval['decided_by'] : '' }}
                            @else
                                Awaiting a decision
                            @endif
                        </p>
                    </div>
                    <span class="nx-approval__state">
                        {{ $approval['decision'] ? ucfirst($approval['decision']) : 'Awaiting' }}
                    </span>
                </li>
            @endforeach
        </ul>
        @unless ($canApprove)
            <p class="nx-section__description">
                You can review this screen but your role does not include
                <code class="nx-code">cutover.approve</code>, so you cannot record decisions here.
            </p>
        @endunless
        </section>
    @endif

    {{-- 6. The ordered plan. --}}
    @if ($ui['cutover.plan']['visibility'] ?? true)
        <section class="nx-section" data-nx-inspect="cutover.plan" data-nx-inspect-label="Ordered cutover plan">
            <h2 class="nx-section__title">Ordered cutover plan</h2>
            <p class="nx-section__description">
                Steps marked as needing approval are gated. The platform records each step; it does not
                execute DNS or endpoint changes.
            </p>
            <ol class="nx-steps">
                @foreach ($steps as $step)
                    <li @class(['nx-step', 'nx-step--gated' => $step['approval_required'], 'nx-step--blocked' => $step['blocked']])>
                        <span class="nx-step__num">{{ $step['step'] }}</span>
                        <div class="nx-step__body">
                            <span class="nx-step__label">
                                {{ $step['label'] }}
                                @if ($step['approval_required'])
                                    <span class="nx-tag nx-tag--approval">approval</span>
                                @endif
                            </span>
                            <span class="nx-step__note">{{ $step['note'] }}</span>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    {{-- 7. Advanced: the raw gate states and rollback procedure (§14). --}}
    <details class="nx-advanced">
        <summary>Advanced details</summary>
        <div class="nx-advanced__body">
            <div class="nx-fact">
                <span class="nx-fact__label">Live Sync raw state</span>
                <span class="nx-fact__value nx-code">{{ $liveSync['raw_state'] }}</span>
                <span class="nx-fact__detail">Internal gate state, shown verbatim.</span>
            </div>
            <div class="nx-fact">
                <span class="nx-fact__label">Live Sync lag</span>
                <span class="nx-fact__value nx-code">
                    {{ $liveSync['lag_seconds'] !== null ? $liveSync['lag_seconds'].'s' : '—' }}
                </span>
            </div>
            <div class="nx-fact">
                <span class="nx-fact__label">Rollback window expires</span>
                <span class="nx-fact__value nx-code">{{ $rollback['expires_at'] ?? '—' }}</span>
            </div>
            <div class="nx-fact">
                <span class="nx-fact__label">Rollback procedure</span>
                <span class="nx-fact__detail">{{ $rollback['procedure'] ?? 'No procedure recorded.' }}</span>
            </div>
            @foreach ($gates as $gate)
                <div class="nx-fact">
                    <span class="nx-fact__label">{{ $gate['gate'] }}</span>
                    <span class="nx-fact__value nx-code">{{ $gate['state'] }}</span>
                    <span class="nx-fact__detail">{{ $gate['evidence'] }}</span>
                </div>
            @endforeach
        </div>
    </details>
</div>
