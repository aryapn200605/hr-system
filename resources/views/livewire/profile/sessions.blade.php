<div class="space-y-6" x-data="{ handle: null }">
    <x-ui.page-header title="My Profile" />
    @include('livewire.profile.nav')

    <x-ui.card title="Active sessions" flush>
        <ul class="divide-y divide-rule">
            @foreach ($sessions as $session)
                <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5" wire:key="session-{{ $session['handle'] ?? 'current' }}">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-ink">
                            {{ $session['device'] }}
                            @if ($session['current'])
                                <x-ui.badge tone="green">This session</x-ui.badge>
                            @endif
                        </p>
                        <p class="mt-0.5 text-sm wrap-anywhere text-muted tabular-nums">
                            {{ $session['ip_address'] ?? 'Unknown address' }}
                            · Last active <x-ui.datetime :value="$session['last_active']" />
                        </p>
                    </div>

                    @unless ($session['current'])
                        <x-ui.button
                            variant="secondary"
                            size="sm"
                            class="shrink-0 self-start sm:self-auto"
                            x-on:click="handle = '{{ $session['handle'] }}'; $dispatch('open-modal', { name: 'end-session' })"
                        >End session</x-ui.button>
                    @endunless
                </li>
            @endforeach
        </ul>

        <x-slot:footer>
            @if ($sessions->count() > 1)
                <x-ui.button variant="danger" size="sm" x-on:click="handle = null; $dispatch('open-modal', { name: 'end-session' })">End all other sessions</x-ui.button>
            @else
                <p class="text-sm text-muted">You have no other sessions.</p>
            @endif

            <form method="POST" action="{{ route('logout') }}" class="sm:ml-auto">
                @csrf

                <x-ui.button type="submit" variant="secondary" size="sm">Log out of this session</x-ui.button>
            </form>
        </x-slot:footer>
    </x-ui.card>

    <x-ui.modal name="end-session" title="End session?">
        <span x-text="handle ? 'That device will be logged out at its next request.' : 'Every other device will be logged out at its next request.'"></span>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', { name: 'end-session' })">Cancel</x-ui.button>
            <x-ui.button
                variant="danger"
                x-on:click="handle ? $wire.terminate(handle) : $wire.terminateOthers(); $dispatch('close-modal', { name: 'end-session' })"
            >End session</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
