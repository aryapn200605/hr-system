<div class="space-y-6">
    <x-ui.page-header title="Security Settings" />

    @if ($recoveryCodesLeft <= 2)
        <x-ui.alert tone="warning">Few recovery codes are left. Regenerate them so you are not locked out if you lose your authenticator.</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
        <x-ui.card title="Two-factor authentication">
            <dl class="divide-y divide-rule">
                <x-ui.detail label="Status"><x-ui.badge tone="green">Enabled</x-ui.badge></x-ui.detail>
                <x-ui.detail label="Configured on"><x-ui.datetime :value="$user->two_factor_enrolled_at" /></x-ui.detail>
                <x-ui.detail label="Recovery codes left">{{ $recoveryCodesLeft }}</x-ui.detail>
            </dl>

            <x-slot:footer>
                <x-ui.button :href="route('security.authenticator')" variant="secondary" size="sm">Reconfigure authenticator</x-ui.button>
                <x-ui.button :href="route('security.recovery-codes')" variant="secondary" size="sm">Regenerate recovery codes</x-ui.button>
            </x-slot:footer>
        </x-ui.card>

        <x-ui.card title="Password">
            <p class="py-2 text-sm text-ink-soft">Changing your password ends all your other sessions.</p>

            <x-slot:footer>
                <x-ui.button :href="route('security.password')" variant="secondary" size="sm">Change password</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </div>
</div>
