<div class="space-y-6">
    <section class="nx-panel p-6 space-y-4">
        <h2 class="text-lg font-semibold">{{ __('connections.project_database') }}</h2>
        <p>{{ __('connections.project_classification') }}: {{ $classification }}</p>
        <p>{{ __('connections.active_environment') }}: <strong>{{ $environment }}</strong></p>
        <p class="text-sm">{{ __('connections.environment_scope_help') }}</p>
        <p role="status">{{ $reachable ? __('connections.connected') : ($configured ? __('connections.unreachable') : ($managed ? __('connections.not_provisioned') : __('connections.not_configured'))) }}</p>
        <p class="text-sm">{{ __('connections.source_separate') }}</p>
        @if ($metadata)
            <dl class="cp-kv">
                @foreach (['host', 'port', 'database', 'username', 'sslmode'] as $field)
                    <dt>{{ __('connections.'.$field) }}</dt><dd><code><bdi dir="ltr">{{ $metadata[$field] ?? '—' }}</bdi></code></dd>
                @endforeach
            </dl>
        @endif
        <p class="text-sm">{{ __('connections.secrets_private') }}</p>
        @if ($canManage)
            <div class="flex flex-wrap gap-3">
                @if (!$configured && $managed)
                    <x-filament::button wire:click="provisionDatabase" wire:loading.attr="disabled">{{ __('connections.provision_for', ['environment' => $environment]) }}</x-filament::button>
                @endif
                <x-filament::button color="gray" wire:click="configureConnection">{{ __('connections.configure') }}</x-filament::button>
                @if ($configured)
                    <x-filament::button color="gray" wire:click="retryConnection">{{ __('connections.retry') }}</x-filament::button>
                @endif
            </div>
        @elseif (!$reachable)
            <p>{{ __('connections.owner_required') }}</p>
        @endif
    </section>
    @if ($this->configuring)
        <form wire:submit="saveConnection" class="nx-panel p-6 space-y-4">
            <h2 class="text-lg font-semibold">{{ __('connections.configure') }}</h2>
            <p>{{ __('connections.configure_help') }}</p>
            @foreach (['host', 'port', 'database', 'username'] as $field)
                <label class="block">{{ __('connections.'.$field) }}
                    <x-filament::input.wrapper><x-filament::input dir="ltr" type="{{ $field === 'port' ? 'number' : 'text' }}" wire:model="connectionForm.{{ $field }}" /></x-filament::input.wrapper>
                </label>
                @error('connectionForm.'.$field)<p class="text-danger-600">{{ $message }}</p>@enderror
            @endforeach
            <label class="block">{{ __('connections.password') }}
                <x-filament::input.wrapper><x-filament::input type="password" autocomplete="new-password" wire:model="connectionPassword" /></x-filament::input.wrapper>
            </label>
            @error('connectionPassword')<p class="text-danger-600">{{ $message }}</p>@enderror
            <label class="block">{{ __('connections.sslmode') }}
                <select wire:model="connectionForm.sslmode"><option>prefer</option><option>require</option><option>verify-ca</option><option>verify-full</option><option>disable</option><option>allow</option></select>
            </label>
            <label class="block">{{ __('connections.destination') }}
                <select wire:model="connectionForm.destination"><option value="temm">{{ __('connections.managed') }}</option><option value="external">{{ __('connections.external') }}</option></select>
            </label>
            <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('connections.save') }}</x-filament::button>
            <x-filament::button type="button" color="gray" wire:click="cancelConfiguration">{{ __('connections.cancel') }}</x-filament::button>
        </form>
    @endif
</div>
