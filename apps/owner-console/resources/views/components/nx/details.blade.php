{{--
    0.6.0 Phase A (§A9) — progressive disclosure.

    Level 3/4 technical and internal information has a standard place to
    live: a quiet disclosure that never participates in the default
    decision-making layer. Useful technical information is hidden, not
    deleted.

    Usage:
      <x-nx.details summary="{{ __('foundation.technical_details') }}">
          ...technical content...
      </x-nx.details>
--}}
@props([
    'summary' => null,
    'open' => false,
])

@php
    $label = $summary ?? __('foundation.technical_details');
@endphp

<details class="nx-details" @if ($open) open @endif {{ $attributes }}>
    <summary class="nx-details__summary">
        <svg class="nx-details__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
        </svg>
        {{ $label }}
    </summary>
    <div class="nx-details__body">
        {{ $slot }}
    </div>
</details>
