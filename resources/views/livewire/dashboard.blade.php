{{-- The pilot page of the redesign, laid out like shadcn-admin's dashboard: figure cards first, then a wide
     card beside a narrow list. Only the data the dashboard already had; no chart. --}}
<x-ui.page :title="__('Welcome, :name', ['name' => $user->name])" icon="home">
    <x-slot:subtitle>
        <span class="mt-1 flex flex-wrap items-center gap-2">
            <x-ui.role-badge :user="$user" />
            <span>{{ __('Here is the current state of your account.') }}</span>
        </span>
    </x-slot:subtitle>

    <x-slot:actions>
        <x-ui.button variant="secondary" size="sm" wire:click="refresh" loading="refresh"><x-ui.icon name="refresh" class="size-4" wire:loading.remove wire:target="refresh" />{{ __('Refresh') }}</x-ui.button>
    </x-slot:actions>

    @include('livewire.partials.dashboard-requests', ['summary' => $summary])

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-7">
        @if ($summary['balance'] !== null)
            @php($last = $summary['lastDecided'])

            <x-ui.card :title="__('My last decided request')" icon="check-circle" :tone="$last?->status->tone() ?? 'gray'" class="lg:col-span-4" data-last-decided>
                @if ($last === null)
                    <x-ui.empty-state :title="__('No decision yet')" :description="__('When one of your requests is approved or rejected, it is shown here.')" icon="clipboard" />
                @else
                    <dl class="divide-y divide-border px-5 py-2">
                        <x-ui.detail :label="__('Type')">{{ $last->type === 'leave' ? __($last->categoryName) : __('Overtime') }}</x-ui.detail>
                        <x-ui.detail :label="__('Dates')">
                            @if ($last->type === 'leave')
                                <x-ui.date :value="$last->starts" /> – <x-ui.date :value="$last->ends" />
                            @else
                                <x-ui.datetime :value="$last->starts" /> – <x-ui.datetime :value="$last->ends" />
                            @endif
                        </x-ui.detail>
                        <x-ui.detail :label="__('Status')"><x-ui.badge :tone="$last->status->tone()" dot data-last-status>{{ $last->status->label() }}</x-ui.badge></x-ui.detail>
                        <x-ui.detail :label="__('Decided')"><x-ui.datetime :value="$last->decidedAt" /></x-ui.detail>
                    </dl>
                    <div class="mt-auto border-t border-border px-5 py-3 text-sm">
                        <a href="{{ route('requests.show', ['type' => $last->type, 'id' => $last->id]) }}" class="inline-flex items-center gap-1.5 font-medium text-primary underline-offset-4 hover:underline" data-last-decided-link>{{ __('Open the request') }}<x-ui.icon name="arrow-right" class="size-4" /></a>
                    </div>
                @endif
            </x-ui.card>
        @endif

        <x-ui.card :title="__('Recent logins')" :description="__('Your five most recent login attempts')" icon="log-in" tone="gray" :class="$summary['balance'] !== null ? 'lg:col-span-3' : 'lg:col-span-7'">
            @if ($recentLogins->isEmpty())
                <x-ui.empty-state :title="__('No login activity yet')" :description="__('Your logins and failed attempts will be listed here.')" icon="log-in" />
            @else
                <ul class="divide-y divide-border px-5 py-2" aria-label="{{ __('Your five most recent login attempts') }}">
                    @foreach ($recentLogins as $login)
                        @php($result = \App\Enums\LoginResult::from($login->result))

                        <li class="flex items-center justify-between gap-4 py-3" wire:key="login-{{ $login->id }}">
                            <div class="flex min-w-0 items-center gap-3">
                            <x-ui.icon-chip :icon="$result->tone() === 'green' ? 'check-circle' : 'x-circle'" :tone="$result->tone()" size="sm" />
                            <div class="min-w-0 space-y-1">
                                <p class="text-sm leading-none font-medium"><x-ui.datetime :value="$login->occurred_at" /></p>
                                <p class="text-sm text-muted-foreground">{{ __('IP address') }}: {{ $login->ip_address ?? __('Unknown') }}</p>
                            </div>
                            </div>
                            <x-ui.badge :tone="$result->tone()" dot>{{ $result->label() }}</x-ui.badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    <section aria-labelledby="account-activity-heading" class="space-y-4">
        <h2 id="account-activity-heading" class="text-lg font-semibold tracking-tight">{{ __('Summary') }}</h2>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-ui.stat :label="__('Successful logins')" icon="check-circle" tone="green" :value="$successfulLogins" :hint="__('Last :days days', ['days' => $summaryDays])" />
            <x-ui.stat :label="__('Failed login attempts')" icon="x-circle" :tone="$failedLogins > 0 ? 'red' : 'gray'" :value="$failedLogins" :hint="__('Last :days days', ['days' => $summaryDays])" />
            <x-ui.stat :label="__('Recovery codes left')" icon="key" :tone="$recoveryCodesLeft > 2 ? 'violet' : 'amber'" :value="$recoveryCodesLeft" :hint="__('Each code works once')" />
        </div>
    </section>

    @isset($accountCounts)
        <x-ui.card :title="__('User accounts')" icon="users">
            <dl class="grid divide-y divide-border sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                @foreach (\App\Enums\UserStatus::cases() as $status)
                    <div class="px-5 py-4">
                        <dt><x-ui.status-badge :status="$status" dot /></dt>
                        <dd class="mt-2 text-2xl font-bold tracking-tight">{{ $accountCounts[$status->value] ?? 0 }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>
    @endisset

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-ui.card :title="__('Security status')" icon="shield">
            <div class="px-5 pt-4">
                @if ($recoveryCodesLeft > 2)
                    <x-ui.alert tone="success">{{ __('Two-factor authentication is protecting your account.') }}</x-ui.alert>
                @else
                    <x-ui.alert tone="warning">{{ __('Two-factor authentication is on, but few recovery codes are left. Keep your authenticator app safe.') }}</x-ui.alert>
                @endif
            </div>

            <dl class="divide-y divide-border px-5 pt-2 pb-2">
                <x-ui.detail :label="__('Two-factor authentication')">
                    @if ($user->hasCompletedTwoFactorEnrollment())
                        <x-ui.badge tone="green" dot>{{ __('Enabled') }}</x-ui.badge>
                    @else
                        <x-ui.badge tone="red" dot>{{ __('Not enabled') }}</x-ui.badge>
                    @endif
                </x-ui.detail>
                @if ($user->two_factor_enrolled_at)
                    <x-ui.detail :label="__('Enabled on')"><x-ui.datetime :value="$user->two_factor_enrolled_at" /></x-ui.detail>
                @endif
                <x-ui.detail :label="__('Recovery codes left')">{{ $recoveryCodesLeft }}</x-ui.detail>
                <x-ui.detail :label="__('Last login')">
                    @if ($user->last_login_at)
                        <x-ui.datetime :value="$user->last_login_at" />
                    @else
                        {{ __('Not recorded yet') }}
                    @endif
                </x-ui.detail>
            </dl>
        </x-ui.card>

        <x-ui.card :title="__('Account information')" icon="user">
            <dl class="divide-y divide-border px-5 py-2">
                <x-ui.detail :label="__('Full name')">{{ $user->name }}</x-ui.detail>
                <x-ui.detail :label="__('Username')">{{ $user->username }}</x-ui.detail>
                <x-ui.detail :label="__('Email')">{{ $user->email }}</x-ui.detail>
                <x-ui.detail :label="__('Role')"><x-ui.role-badge :user="$user" /></x-ui.detail>
                <x-ui.detail :label="__('Status')"><x-ui.status-badge :status="$user->status" /></x-ui.detail>
                <x-ui.detail :label="__('Member since')"><x-ui.datetime :value="$user->created_at" /></x-ui.detail>
            </dl>
        </x-ui.card>
    </div>
</x-ui.page>
