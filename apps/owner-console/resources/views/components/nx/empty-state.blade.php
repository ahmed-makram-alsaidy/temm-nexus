{{--
    0.6.0 Phase A (§A8) — THE empty state system.

    icon → title → one sentence → optional primary action → optional
    secondary action. A blank table is not an empty state; this is.

    Usage:
      <x-nx.empty-state
          icon="heroicon-o-folder"
          :title="__('...')"
          :body="__('...')"
          :actionUrl="..."
          :actionLabel="..."
      />
--}}
@props([
    'icon' => 'heroicon-o-inbox',
    'title' => null,
    'body' => null,
    'actionUrl' => null,
    'actionLabel' => null,
    'secondaryUrl' => null,
    'secondaryLabel' => null,
    'compact' => false,
])

@php
    $t = $title ?? __('foundation.empty_title');
    // Small curated glyph set: the empty state renders everywhere, including
    // plain blades and Livewire banners, without Filament component deps.
    $paths = [
        'heroicon-o-inbox' => 'M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z',
        'heroicon-o-folder' => 'M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z',
        'heroicon-o-square-3-stack-3d' => 'm6.75 7.5 3 2.25-3 2.25m3 2.25-3-2.25V21m7.5-9 3-2.25M12 10.5V21m0-10.5 3 2.25m-3-2.25-3 2.25M3.577 3.865l7.53-4.006a1.8 1.8 0 0 1 1.786 0l7.53 4.006a1.8 1.8 0 0 1 0 3.17l-7.53 4.007a1.8 1.8 0 0 1-1.786 0l-7.53-4.007a1.8 1.8 0 0 1 0-3.17Z',
        'heroicon-o-cog-6-tooth' => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    ];
    $d = $paths[$icon] ?? $paths['heroicon-o-inbox'];
@endphp

<div @class(['nx-emptystate', 'nx-emptystate--compact' => $compact])>
    <span class="nx-emptystate__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" width="24" height="24">
            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $d }}" />
        </svg>
    </span>
    <h3 class="nx-emptystate__title">{{ $t }}</h3>
    @if (filled($body))
        <p class="nx-emptystate__body">{{ $body }}</p>
    @endif
    @if (filled($actionUrl) && filled($actionLabel))
        <div class="nx-emptystate__actions">
            <a class="nx-btn" href="{{ $actionUrl }}">{{ $actionLabel }}</a>
            @if (filled($secondaryUrl) && filled($secondaryLabel))
                <a class="nx-btn" href="{{ $secondaryUrl }}">{{ $secondaryLabel }}</a>
            @endif
        </div>
    @endif
</div>
