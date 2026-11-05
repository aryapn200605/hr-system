<div class="space-y-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold tracking-tight wrap-anywhere text-slate-900">{{ __('Welcome, :name', ['name' => $user->name]) }}</h1>
            <p class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                <x-ui.role-badge :user="$user" />
                <span>{{ __('Here is the current state of your account.') }}</span>
            </p>
        </div>

        <x-ui.button variant="secondary" size="sm" class="self-start" wire:click="refresh" loading="refresh">{{ __('Refresh') }}</x-ui.button>
    </header>

    @include('livewire.partials.dashboard-requests', ['summary' => $summary])

    <section aria-label="{{ __('Summary') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat :label="__('Successful logins')" :value="$successfulLogins" :hint="__('Last :days days', ['days' => $summaryDays])" />
        <x-ui.stat :label="__('Failed login attempts')" :value="$failedLogins" :hint="__('Last :days days', ['days' => $summaryDays])" />
        <x-ui.stat :label="__('Recovery codes left')" :value="$recoveryCodesLeft" :hint="__('Each code works once')" />
    </section>

    @isset($accountCounts)
        <x-ui.card :title="__('User accounts')">
            <dl class="grid divide-y divide-slate-200 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                @foreach (\App\Enums\UserStatus::cases() as $status)
                    <div class="px-5 py-4">
                        <dt><x-ui.status-badge :status="$status" /></dt>
                        <dd class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ $accountCounts[$status->value] ?? 0 }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>
    @endisset

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Security status')">
            <div class="px-5 pt-4">
                @if ($recoveryCodesLeft > 2)
                    <x-ui.alert tone="success">{{ __('Two-factor authentication is protecting your account.') }}</x-ui.alert>
                @else
                    <x-ui.alert tone="warning">{{ __('Two-factor authentication is on, but few recovery codes are left. Keep your authenticator app safe.') }}</x-ui.alert>
                @endif
            </div>

            <dl class="divide-y divide-slate-200 px-5 pt-2 pb-2">
                <x-ui.detail :label="__('Two-factor authentication')">
                    @if ($user->hasCompletedTwoFactorEnrollment())
                        <x-ui.badge tone="green">{{ __('Enabled') }}</x-ui.badge>
                    @else
                        <x-ui.badge tone="red">{{ __('Not enabled') }}</x-ui.badge>
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

        <x-ui.card :title="__('Account information')">
            <dl class="divide-y divide-slate-200 px-5 py-2">
                <x-ui.detail :label="__('Full name')">{{ $user->name }}</x-ui.detail>
                <x-ui.detail :label="__('Username')">{{ $user->username }}</x-ui.detail>
                <x-ui.detail :label="__('Email')">{{ $user->email }}</x-ui.detail>
                <x-ui.detail :label="__('Role')"><x-ui.role-badge :user="$user" /></x-ui.detail>
                <x-ui.detail :label="__('Status')"><x-ui.status-badge :status="$user->status" /></x-ui.detail>
                <x-ui.detail :label="__('Member since')"><x-ui.datetime :value="$user->created_at" /></x-ui.detail>
            </dl>
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Recent logins')">
        @if ($recentLogins->isEmpty())
            <x-ui.empty-state :title="__('No login activity yet')" :description="__('Your logins and failed attempts will be listed here.')" />
        @else
            <x-ui.table :caption="__('Your five most recent login attempts')">
                <x-slot:head>
                    <tr>
                        <th scope="col">{{ __('Time') }}</th>
                        <th scope="col">{{ __('Result') }}</th>
                        <th scope="col">{{ __('IP address') }}</th>
                    </tr>
                </x-slot:head>

                @foreach ($recentLogins as $login)
                    @php($result = \App\Enums\LoginResult::from($login->result))

                    <tr wire:key="login-{{ $login->id }}">
                        <td><x-ui.datetime :value="$login->occurred_at" /></td>
                        <td><x-ui.badge :tone="$result->tone()">{{ $result->label() }}</x-ui.badge></td>
                        <td>{{ $login->ip_address ?? __('Unknown') }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
</div>
