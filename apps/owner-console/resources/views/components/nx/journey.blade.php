{{--
    0.4.0 §6 — the migration journey stepper.

    Renders the seven stages with their product state. This is the spine of the
    project scope: it answers "where am I / what is done / what is blocked /
    what is next" in one glance.

    Props:
      $journey  list<array{stage: JourneyStage, state: JourneyState, detail: string, url: ?string}>
      $current  JourneyStage — the stage to mark as "you are here"

    Every step shows an icon AND a label, so state is never colour-only (§34).
--}}
@props(['journey', 'current' => null])

<ol class="nx-journey" role="list">
    @foreach ($journey as $step)
        @php
            $stage = $step['stage'];
            $state = $step['state'];
            $isCurrent = $current !== null && $stage === $current;
        @endphp

        <li @class([
            'nx-journey__step',
            'nx-journey__step--'.$state->tone(),
            'is-current' => $isCurrent,
        ])>
            <a class="nx-journey__link"
               href="{{ $step['url'] ?? '#' }}"
               @if ($isCurrent) aria-current="step" @endif>

                <span class="nx-journey__marker">
                    <x-filament::icon :icon="$state->icon()" class="h-4 w-4" />
                </span>

                <span class="nx-journey__body">
                    <span class="nx-journey__label">
                        {{ $stage->label() }}
                        @if ($isCurrent)
                            <span class="nx-journey__here">{{ __('labels.you_are_here') }}</span>
                        @endif
                    </span>
                    <span class="nx-journey__state">{{ $state->label() }}</span>
                    <span class="nx-journey__detail">{{ $step['detail'] }}</span>
                </span>
            </a>
        </li>
    @endforeach
</ol>
