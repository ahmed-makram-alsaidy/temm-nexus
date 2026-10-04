{{--
    0.6.0 Phase A (A7) - THE error presentation pattern.

    WHAT FAILED (plain title), then what it means / what to do, then the
    primary recovery action, then Technical details - exception text,
    SQLSTATE, provider bodies and capability slugs live ONLY there.

    Deliberately free of Filament component dependencies (inline glyph),
    so it renders identically inside Filament schema Views, Livewire
    banners and plain blades.

    Usage:
      payload: array{title, body?, technical?} from NxError::forException(),
      or explicit title/body/technical props, plus an optional Livewire retry.
--}}

@props([
    'payload' => null,      // array{title, body?, technical?} from NxError::forException()
    'title' => null,
    'body' => null,
    'technical' => null,
    'retry' => null,        // Livewire action name for the recovery button
    'retryLabel' => null,
])

@php
    $t = $payload['title'] ?? $title ?? __('foundation.error_title');
    $b = $payload['body'] ?? $body;
    $tech = $payload['technical'] ?? $technical;
@endphp

<div class="nx-error" role="alert">
    <span class="nx-error__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="20" height="20">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
        </svg>
    </span>

    <div class="nx-error__content">
        <p class="nx-error__title">{{ $t }}</p>
        @if (filled($b))
            <p class="nx-error__body">{{ $b }}</p>
        @endif

        @if (filled($retry) || filled($tech))
            <div class="nx-error__actions">
                @if (filled($retry))
                    <button type="button" class="nx-btn" wire:click="{{ $retry }}">
                        {{ $retryLabel ?? __('foundation.error_retry') }}
                    </button>
                @endif
                @if (filled($tech))
                    <details class="nx-details nx-details--inline">
                        <summary class="nx-details__summary">{{ __('foundation.error_technical_details') }}</summary>
                        <div class="nx-details__body" dir="ltr">
                            <code class="nx-tech">{{ $tech }}</code>
                        </div>
                    </details>
                @endif
            </div>
        @endif
    </div>
</div>
