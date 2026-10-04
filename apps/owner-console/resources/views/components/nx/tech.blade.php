{{--
    0.6.0 Phase A (§A10) — technical values.

    Paths, model ids, commit hashes, commands, URLs, IPs and database
    identifiers stay LTR and monospace inside Arabic (RTL) pages without
    breaking the surrounding layout. Pair with the copy affordance when
    the value is used in runbooks.

    Usage:
      <x-nx.tech>postgres://…</x-nx.tech>
      <x-nx.tech copy>gpt-4.1-mini</x-nx.tech>
--}}
@props([
    'copy' => false,
])

@php
    $value = trim((string) $slot);
@endphp

<span class="nx-tech-wrap">
    <code class="nx-tech" dir="ltr" {{ $attributes }}>{{ $value }}</code>
    @if ($copy)
        <button
            type="button"
            class="nx-tech__copy"
            dir="ltr"
            data-nx-copy="{{ $value }}"
            title="{{ __('foundation.copy') }}"
            aria-label="{{ __('foundation.copy') }}"
        >{{ __('foundation.copy') }}</button>
    @endif
</span>
