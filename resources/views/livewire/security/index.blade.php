<div class="space-y-6">
    @include('security.partials.page-header', ['title' => __('Security Settings')])

    @if ($recoveryCodesLeft <= 2)
        <x-ui.alert tone="warning">{{ __('Few recovery codes are left. Regenerate them so you are not locked out if you lose your authenticator.') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Two-factor authentication')">
            <dl class="divide-y divide-slate-200 px-5 py-2">
                <x-ui.detail :label="__('Status')"><x-ui.badge tone="green">{{ __('Enabled') }}</x-ui.badge></x-ui.detail>
                <x-ui.detail :label="__('Configured on')"><x-ui.datetime :value="$user->two_factor_enrolled_at" /></x-ui.detail>
                <x-ui.detail :label="__('Recovery codes left')">{{ $recoveryCodesLeft }}</x-ui.detail>
            </dl>

            <div class="flex flex-col gap-2 border-t border-slate-200 px-5 py-3.5 sm:flex-row sm:flex-wrap">
                <x-ui.button :href="route('security.authenticator')" variant="secondary" size="sm">{{ __('Reconfigure authenticator') }}</x-ui.button>
                <x-ui.button :href="route('security.recovery-codes')" variant="secondary" size="sm">{{ __('Regenerate recovery codes') }}</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Password')" class="self-start">
            <p class="px-5 pt-4 text-sm text-slate-600">{{ __('Changing your password ends all your other sessions.') }}</p>

            <div class="px-5 py-3.5">
                <x-ui.button :href="route('security.password')" variant="secondary" size="sm">{{ __('Change password') }}</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
