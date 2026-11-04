<x-layouts::auth :title="__('Recovery codes')">
    <x-ui.auth-heading :title="__('Save your recovery codes')">{{ __('Each code works once, for when your authenticator is unavailable. They are shown only now and cannot be displayed again.') }}</x-ui.auth-heading>

    <ul class="mb-6 grid grid-cols-1 gap-1.5 rounded-lg border border-slate-200 bg-slate-50 p-4 font-mono text-sm text-slate-900 sm:grid-cols-2">
        @foreach ($recoveryCodes as $recoveryCode)
            <li>{{ $recoveryCode }}</li>
        @endforeach
    </ul>

    @isset($notice)
        <x-ui.alert class="mb-6">{{ $notice }}</x-ui.alert>
    @endisset

    <x-ui.button :href="$continueUrl ?? route('dashboard')" block>{{ $continueLabel ?? __('I have saved them') }}</x-ui.button>
</x-layouts::auth>
