<div class="space-y-8">
    @include('security.partials.page-header', ['title' => 'Security Settings'])
    @include('security.partials.nav')

    @if ($recoveryCodesLeft <= 2)
        <x-ui.alert tone="warning">Few recovery codes are left. Regenerate them so you are not locked out if you lose your authenticator.</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-x-10 gap-y-8 lg:grid-cols-2">
        <x-ui.card title="Two-factor authentication">
            <dl class="divide-y divide-rule py-1">
                <x-ui.detail label="Status"><x-ui.badge tone="green">Enabled</x-ui.badge></x-ui.detail>
                <x-ui.detail label="Configured on"><x-ui.datetime :value="$user->two_factor_enrolled_at" /></x-ui.detail>
                <x-ui.detail label="Recovery codes left">{{ $recoveryCodesLeft }}</x-ui.detail>
            </dl>

            <div class="flex flex-col gap-2 border-t border-rule py-2.5 sm:flex-row sm:flex-wrap">
                <x-ui.button :href="route('security.authenticator')" variant="secondary" size="sm">Reconfigure authenticator</x-ui.button>
                <x-ui.button :href="route('security.recovery-codes')" variant="secondary" size="sm">Regenerate recovery codes</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.card title="Password" class="self-start">
            <p class="pt-3 text-sm text-ink-soft">Changing your password ends all your other sessions.</p>

            <div class="py-2.5">
                <x-ui.button :href="route('security.password')" variant="secondary" size="sm">Change password</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
