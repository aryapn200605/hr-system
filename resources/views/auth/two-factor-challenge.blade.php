<x-layouts::auth :title="__('Two-factor authentication')">
    {{-- Two forms, as before: the code and the recovery code go to their own routes. Without JavaScript both are
         shown, one under the other; with it, one at a time (resources/js/auth.js, data-auth-switch). --}}
    <div class="group/switch" data-auth-switch data-switch-initial="{{ $errors->has('recovery_code') ? 'recovery' : 'code' }}">
        <section data-switch-panel="code" id="authenticator-code">
            <x-ui.auth-heading icon="shield" :title="__('Two-factor authentication')">{{ __('Open your authenticator app and enter the six-digit code it shows for this account.') }}</x-ui.auth-heading>

            <form method="POST" action="{{ route('two-factor.challenge.store') }}" class="grid gap-4">
                @csrf

                <x-ui.otp-input script="vanilla" :label="__('Six-digit code from your authenticator app')" id="code" name="code" required autofocus />

                <x-ui.submit-button>{{ __('Verify') }}</x-ui.submit-button>
            </form>

            <p class="mt-6 text-center text-sm text-muted-foreground">
                {{ __('No access to your app?') }}
                <a href="#recovery-code-form" data-switch-to="recovery" class="rounded-sm font-medium text-foreground underline underline-offset-4 hover:text-primary">{{ __('Use a recovery code') }}</a>
            </p>
        </section>

        <section data-switch-panel="recovery" id="recovery-code-form" class="mt-10 border-t border-border pt-8 group-data-enhanced/switch:mt-0 group-data-enhanced/switch:border-0 group-data-enhanced/switch:pt-0">
            <x-ui.auth-heading icon="key" :level="2" :title="__('Use a recovery code')">{{ __('Enter one of the recovery codes you saved when you set up two-factor authentication. Each code works once.') }}</x-ui.auth-heading>

            <form method="POST" action="{{ route('two-factor.recovery.store') }}" class="grid gap-4">
                @csrf

                <x-ui.input :label="__('Recovery code')" name="recovery_code" icon="key" required autocomplete="off" autocapitalize="characters" spellcheck="false" class="font-mono" />

                <x-ui.submit-button>{{ __('Use recovery code') }}</x-ui.submit-button>
            </form>

            <p class="mt-6 text-center text-sm text-muted-foreground">
                <a href="#authenticator-code" data-switch-to="code" class="rounded-sm font-medium text-foreground underline underline-offset-4 hover:text-primary">{{ __('Use the code from your app instead') }}</a>
            </p>
        </section>
    </div>

    <form method="POST" action="{{ route('two-factor.cancel') }}" class="mt-6 border-t border-border pt-4 text-center">
        @csrf
        <x-ui.button type="submit" variant="link">{{ __('Cancel and go back to login') }}</x-ui.button>
    </form>
</x-layouts::auth>
