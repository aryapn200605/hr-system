<x-ui.page :title="__('My Profile')" icon="user" x-data="{ handle: null }">
    @include('profile.partials.nav')

    <x-ui.card :title="__('Active sessions')" icon="monitor">
        <ul class="divide-y divide-border">
            @foreach ($sessions as $session)
                <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between" wire:key="session-{{ $session['handle'] ?? 'current' }}">
                    <div class="flex min-w-0 items-start gap-3">
                        <x-ui.icon-chip :icon="preg_match('/android|iphone|ipad|ios|mobile/i', $session['device']) ? 'smartphone' : 'monitor'" :tone="$session['current'] ? 'green' : 'gray'" size="sm" />
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-foreground">
                            {{ $session['device'] }}
                            @if ($session['current'])
                                <x-ui.badge tone="green" dot>{{ __('This session') }}</x-ui.badge>
                            @endif
                        </p>
                        <p class="mt-1 text-sm wrap-anywhere text-muted-foreground">
                            {{ $session['ip_address'] ?? __('Unknown address') }}
                            · {{ __('Last active') }} <x-ui.datetime :value="$session['last_active']" />
                        </p>
                    </div>
                    </div>

                    @unless ($session['current'])
                        <x-ui.button
                            variant="secondary"
                            size="sm"
                            class="shrink-0 self-start sm:self-auto"
                            x-on:click="handle = '{{ $session['handle'] }}'; $dispatch('open-modal', { name: 'end-session' })"
                        ><x-ui.icon name="log-out" class="size-4" />{{ __('End session') }}</x-ui.button>
                    @endunless
                </li>
            @endforeach
        </ul>

        <div class="mt-auto flex flex-col gap-2 rounded-b-xl border-t border-border bg-muted/40 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between">
            @if ($sessions->count() > 1)
                <x-ui.button variant="danger" size="sm" x-on:click="handle = null; $dispatch('open-modal', { name: 'end-session' })">{{ __('End all other sessions') }}</x-ui.button>
            @else
                <p class="text-sm text-muted-foreground">{{ __('You have no other sessions.') }}</p>
            @endif

            <form method="POST" action="{{ route('logout') }}">
                @csrf

                <x-ui.button type="submit" variant="secondary" size="sm">{{ __('Log out of this session') }}</x-ui.button>
            </form>
        </div>
    </x-ui.card>

    <x-ui.modal name="end-session" icon="log-out" tone="warning" :title="__('End session?')">
        <span x-show="handle">{{ __('That device will be logged out at its next request.') }}</span>
        <span x-show="! handle">{{ __('Every other device will be logged out at its next request.') }}</span>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', { name: 'end-session' })">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button
                variant="danger"
                x-on:click="handle ? $wire.terminate(handle) : $wire.terminateOthers(); $dispatch('close-modal', { name: 'end-session' })"
            >{{ __('End session') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
