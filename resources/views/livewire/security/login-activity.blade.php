<div class="space-y-6">
    <x-ui.page-header title="Security Settings" />
    @include('security.partials.nav')

    <x-ui.card title="Login history" flush>
        @if ($logins->isEmpty())
            <x-ui.empty-state title="No login activity yet" description="Your logins and failed attempts will be listed here." />
        @else
            <x-ui.table caption="Your login attempts, newest first">
                <x-slot:head>
                    <tr>
                        <th scope="col">Time</th>
                        <th scope="col">IP address</th>
                        <th scope="col">User agent</th>
                        <th scope="col">Result</th>
                    </tr>
                </x-slot:head>

                @foreach ($logins as $login)
                    @php($result = \App\Enums\LoginResult::from($login->result))

                    <tr wire:key="login-{{ $login->id }}">
                        <td data-primary><x-ui.datetime :value="$login->occurred_at" /></td>
                        <td data-label="IP address">{{ $login->ip_address ?? 'Unknown' }}</td>
                        <td data-label="Device" title="{{ $login->user_agent }}" class="w-full max-w-0 truncate">{{ \App\Support\UserAgentLabel::for($login->user_agent) }}</td>
                        <td data-label="Result"><x-ui.badge :tone="$result->tone()">{{ $result->label() }}</x-ui.badge></td>
                    </tr>
                @endforeach
            </x-ui.table>

            <x-ui.pagination :paginator="$logins" />
        @endif
    </x-ui.card>
</div>
