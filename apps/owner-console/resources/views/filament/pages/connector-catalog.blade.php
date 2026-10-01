<x-filament-panels::page>
    @php($rows = $this->catalogRows())
    <div class="space-y-4">
        <div class="flex gap-2">
            @foreach (['installed' => 'Installed', 'official' => 'Official', 'community' => 'Community', 'private' => 'Private'] as $section => $label)
                <button
                    type="button"
                    wire:click="$set('activeSection', '{{ $section }}')"
                    @class([
                        'px-3 py-1.5 rounded-lg text-sm font-medium',
                        'bg-primary-500 text-white' => $this->activeSection === $section,
                        'bg-gray-100 dark:bg-gray-800' => $this->activeSection !== $section,
                    ])
                >{{ $label }}</button>
            @endforeach
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($rows as $row)
                @if ($this->activeSection === 'installed' || $row['section'] === $this->activeSection)
                    <div class="fi-section rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold">{{ $row['name'] }}</span>
                            <span class="fi-badge text-xs">{{ $row['trust'] }}</span>
                        </div>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            key: <code>{{ $row['key'] }}</code> · v{{ $row['version'] }}
                        </p>
                        <p class="mt-1 text-sm">
                            {{ $row['enabled'] ? 'Enabled' : 'Disabled' }}
                        </p>
                    </div>
                @endif
            @empty
                <p class="text-sm text-gray-500">No connectors registered.</p>
            @endforelse
        </div>

        <p class="text-xs text-gray-400">
            Local/catalog-backed listing only — remote marketplace and billing are not part of this release (35F).
            Cryptographic signature verification is PLANNED; packages installed through the installer carry verified
            sha256 checksums.
        </p>
    </div>
</x-filament-panels::page>
