<x-ui.page :title="__('Security Settings')" icon="shield">

    @if ($recoveryCodesLeft <= 2)
        <x-ui.alert tone="warning">{{ __('Few recovery codes are left. Regenerate them so you are not locked out if you lose your authenticator.') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Two-factor authentication')" icon="smartphone" tone="green">
            <dl class="divide-y divide-border px-5 py-2">
                <x-ui.detail :label="__('Status')"><x-ui.badge tone="green" dot>{{ __('Enabled') }}</x-ui.badge></x-ui.detail>
                <x-ui.detail :label="__('Configured on')"><x-ui.datetime :value="$user->two_factor_enrolled_at" /></x-ui.detail>
                <x-ui.detail :label="__('Recovery codes left')">{{ $recoveryCodesLeft }}</x-ui.detail>
            </dl>

            <x-slot:footer>
                <x-ui.button :href="route('security.authenticator')" variant="secondary" size="sm"><x-ui.icon name="smartphone" class="size-4" />{{ __('Reconfigure authenticator') }}</x-ui.button>
                <x-ui.button :href="route('security.recovery-codes')" variant="secondary" size="sm"><x-ui.icon name="key" class="size-4" />{{ __('Regenerate recovery codes') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.card>

        <x-ui.card :title="__('Password')" icon="lock">
            <p class="flex items-start gap-2 px-5 py-4 text-sm text-muted-foreground">
                <x-ui.icon name="info" class="mt-0.5 size-4 shrink-0" />
                <span>{{ __('Changing your password ends all your other sessions.') }}</span>
            </p>

            <x-slot:footer>
                <x-ui.button :href="route('security.password')" variant="secondary" size="sm"><x-ui.icon name="lock" class="size-4" />{{ __('Change password') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </div>
</x-ui.page>
