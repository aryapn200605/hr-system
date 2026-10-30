<x-layouts::auth title="Recovery codes">
    <x-ui.auth-heading title="Save your recovery codes">Each code works once, for when your authenticator is unavailable. They are shown only now and cannot be displayed again.</x-ui.auth-heading>

    <ul class="mb-6 grid grid-cols-1 gap-1.5 rounded-lg border border-slate-200 bg-slate-50 p-4 font-mono text-sm text-slate-900 sm:grid-cols-2">
        @foreach ($recoveryCodes as $recoveryCode)
            <li>{{ $recoveryCode }}</li>
        @endforeach
    </ul>

    <p class="mb-6 text-sm text-slate-600">If you lose these codes, create a new set in Security Settings after logging in. That needs your password and your authenticator.</p>

    @isset($notice)
        <x-ui.alert class="mb-6">{{ $notice }}</x-ui.alert>
    @endisset

    <x-ui.button :href="$continueUrl ?? route('dashboard')" block>{{ $continueLabel ?? 'I have saved them' }}</x-ui.button>
</x-layouts::auth>
