<x-ui.page :title="__('My Profile')" icon="user">
    @include('profile.partials.nav')

    <x-ui.card :title="__('Login history')" icon="history">
        <x-ui.table-skeleton target="gotoPage,nextPage,previousPage" :columns="4" :rows="5" />
        <div wire:loading.delay.remove wire:target="gotoPage,nextPage,previousPage">
        @if ($logins->isEmpty())
            <x-ui.empty-state :title="__('No login activity yet')" :description="__('Your logins and failed attempts will be listed here.')" icon="log-in" />
        @else
            <x-ui.table :caption="__('Your login attempts, newest first')">
                <x-slot:head>
                    <tr>
                        <th scope="col">{{ __('Time') }}</th>
                        <th scope="col">{{ __('IP address') }}</th>
                        <th scope="col">{{ __('User agent') }}</th>
                        <th scope="col">{{ __('Result') }}</th>
                    </tr>
                </x-slot:head>

                @foreach ($logins as $login)
                    @php($result = \App\Enums\LoginResult::from($login->result))

                    <tr wire:key="login-{{ $login->id }}">
                        <td><x-ui.datetime :value="$login->occurred_at" /></td>
                        <td>{{ $login->ip_address ?? __('Unknown') }}</td>
                        <td title="{{ $login->user_agent }}">{{ \App\Support\UserAgentLabel::for($login->user_agent) }}</td>
                        <td><x-ui.badge :tone="$result->tone()" dot>{{ $result->label() }}</x-ui.badge></td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
        </div>

        <x-ui.pagination :paginator="$logins" />
    </x-ui.card>
</x-ui.page>
