<div class="space-y-6">
    @include('security.partials.page-header', ['title' => 'Security Settings'])
    @include('security.partials.nav')

    <x-ui.card title="Login history">
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
                        <td><x-ui.datetime :value="$login->occurred_at" /></td>
                        <td>{{ $login->ip_address ?? 'Unknown' }}</td>
                        <td title="{{ $login->user_agent }}">{{ \App\Support\UserAgentLabel::for($login->user_agent) }}</td>
                        <td><x-ui.badge :tone="$result->tone()">{{ $result->label() }}</x-ui.badge></td>
                    </tr>
                @endforeach
            </x-ui.table>

            <nav aria-label="Pagination" class="flex flex-col gap-3 border-t border-slate-200 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-slate-600">Showing {{ $logins->firstItem() }} to {{ $logins->lastItem() }} of {{ $logins->total() }}</p>

                @if ($logins->hasPages())
                    <div class="flex gap-2">
                        <x-ui.button variant="secondary" size="sm" wire:click="previousPage" loading="previousPage" :disabled="$logins->onFirstPage()">Previous</x-ui.button>
                        <x-ui.button variant="secondary" size="sm" wire:click="nextPage" loading="nextPage" :disabled="! $logins->hasMorePages()">Next</x-ui.button>
                    </div>
                @endif
            </nav>
        @endif
    </x-ui.card>
</div>
