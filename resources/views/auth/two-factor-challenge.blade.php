<x-layouts::auth :title="__('Two-factor authentication')">
    <x-ui.auth-heading :title="__('Two-factor authentication')" />

    <form method="POST" action="{{ route('two-factor.challenge.store') }}" class="space-y-4">
        @csrf

        <x-ui.input :label="__('Six-digit code from your authenticator app')" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code" />

        <x-ui.button type="submit" block>{{ __('Verify') }}</x-ui.button>
    </form>

    <details class="mt-6 border-t border-slate-200 pt-4" @if ($errors->has('recovery_code')) open @endif>
        <summary class="cursor-pointer rounded text-sm font-medium text-slate-800">{{ __('Use a recovery code') }}</summary>

        <form method="POST" action="{{ route('two-factor.recovery.store') }}" class="mt-4 space-y-4">
            @csrf

            <x-ui.input :label="__('Recovery code')" name="recovery_code" required autocomplete="off" />

            <x-ui.button type="submit" variant="secondary" block>{{ __('Use recovery code') }}</x-ui.button>
        </form>
    </details>

    <form method="POST" action="{{ route('two-factor.cancel') }}" class="mt-6 text-center">
        @csrf
        <x-ui.button type="submit" variant="link">{{ __('Cancel') }}</x-ui.button>
    </form>
</x-layouts::auth>
