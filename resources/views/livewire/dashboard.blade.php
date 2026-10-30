<div class="space-y-6">
    <x-ui.page-header :title="'Welcome, '.$user->name">
        <p class="mt-1.5 flex flex-wrap items-center gap-2 text-sm text-muted">
            <x-ui.role-badge :user="$user" />
            <span>Your account and login activity for the last {{ $summaryDays }} days.</span>
        </p>

        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" wire:click="refresh" loading="refresh">Refresh</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <section aria-label="Summary" class="grid grid-cols-1 divide-y divide-rule rounded-sm border border-rule bg-surface sm:grid-cols-3 sm:divide-x sm:divide-y-0">
        <x-ui.stat label="Successful logins" :value="$successfulLogins" :hint="'Last '.$summaryDays.' days'" />
        <x-ui.stat label="Failed login attempts" :value="$failedLogins" :hint="'Last '.$summaryDays.' days'" />
        <x-ui.stat label="Recovery codes left" :value="$recoveryCodesLeft" hint="Each code works once" />
    </section>

    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:order-last">
            <x-ui.card title="Security status">
                <div class="pt-2 pb-1">
                    @if ($recoveryCodesLeft > 2)
                        <x-ui.alert tone="success">Two-factor authentication is protecting your account.</x-ui.alert>
                    @else
                        <x-ui.alert tone="warning">Two-factor authentication is on, but few recovery codes are left. Keep your authenticator app safe.</x-ui.alert>
                    @endif
                </div>

                <dl class="divide-y divide-rule">
                    <x-ui.detail label="Two-factor authentication">
                        @if ($user->hasCompletedTwoFactorEnrollment())
                            <x-ui.badge tone="green">Enabled</x-ui.badge>
                        @else
                            <x-ui.badge tone="red">Not enabled</x-ui.badge>
                        @endif
                    </x-ui.detail>
                    @if ($user->two_factor_enrolled_at)
                        <x-ui.detail label="Enabled on"><x-ui.datetime :value="$user->two_factor_enrolled_at" /></x-ui.detail>
                    @endif
                    <x-ui.detail label="Recovery codes left">{{ $recoveryCodesLeft }}</x-ui.detail>
                    <x-ui.detail label="Last login">
                        @if ($user->last_login_at)
                            <x-ui.datetime :value="$user->last_login_at" />
                        @else
                            Not recorded yet
                        @endif
                    </x-ui.detail>
                </dl>
            </x-ui.card>

            @isset($accountCounts)
                <x-ui.card title="User accounts">
                    <dl class="divide-y divide-rule">
                        @foreach (\App\Enums\UserStatus::cases() as $status)
                            <div class="flex items-baseline justify-between gap-3 py-2.5">
                                <dt><x-ui.status-badge :status="$status" /></dt>
                                <dd class="text-sm font-semibold text-ink tabular-nums">{{ $accountCounts[$status->value] ?? 0 }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-ui.card>
            @endisset
        </div>

        <div class="min-w-0 space-y-6 xl:col-span-2">
            <x-ui.card title="Recent logins" flush>
                @if ($recentLogins->isEmpty())
                    <x-ui.empty-state title="No login activity yet" description="Your logins and failed attempts will be listed here." />
                @else
                    <x-ui.table caption="Your five most recent login attempts">
                        <x-slot:head>
                            <tr>
                                <th scope="col">Time</th>
                                <th scope="col">Result</th>
                                <th scope="col">IP address</th>
                            </tr>
                        </x-slot:head>

                        @foreach ($recentLogins as $login)
                            @php($result = \App\Enums\LoginResult::from($login->result))

                            <tr wire:key="login-{{ $login->id }}">
                                <td data-primary><x-ui.datetime :value="$login->occurred_at" /></td>
                                <td data-label="Result"><x-ui.badge :tone="$result->tone()">{{ $result->label() }}</x-ui.badge></td>
                                <td data-label="IP address">{{ $login->ip_address ?? 'Unknown' }}</td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>

            <x-ui.card title="Account information">
                <dl class="divide-y divide-rule">
                    <x-ui.detail label="Full name">{{ $user->name }}</x-ui.detail>
                    <x-ui.detail label="Username">{{ $user->username }}</x-ui.detail>
                    <x-ui.detail label="Email">{{ $user->email }}</x-ui.detail>
                    <x-ui.detail label="Role"><x-ui.role-badge :user="$user" /></x-ui.detail>
                    <x-ui.detail label="Status"><x-ui.status-badge :status="$user->status" /></x-ui.detail>
                    <x-ui.detail label="Member since"><x-ui.datetime :value="$user->created_at" /></x-ui.detail>
                </dl>
            </x-ui.card>
        </div>
    </div>
</div>
