<x-layouts::app :title="__('Confirm your identity')" :breadcrumbs="[
    ['label' => __('Home'), 'href' => route('dashboard')],
    ['label' => __('Security Settings'), 'href' => route('security.index')],
    ['label' => __('Confirm your identity')],
]">
    {{-- A controller page (no Livewire snapshot for a password or a code). The same plain-JavaScript components as
         the auth pages. One form: the code and the recovery code are alternatives (the server refuses both); with
         JavaScript the hidden one is disabled so only one is sent, without it both are shown with the hint. --}}
    @vite('resources/js/auth.js')

    <x-ui.page width="narrow" :title="__('Confirm your identity')" :subtitle="__('Enter your password and a second factor to continue. This confirmation lasts for one action, for up to 10 minutes.')">

        <x-ui.card class="p-5 sm:p-6" data-card>
            <form method="POST" action="{{ route('security.confirm.store') }}" class="grid gap-5">
                @csrf

                <x-ui.password-input :label="__('Password')" name="password" required autofocus autocomplete="current-password" />

                <div class="group/switch grid gap-5" data-auth-switch data-disable-hidden="true" data-switch-initial="{{ $errors->has('recovery_code') ? 'recovery' : 'code' }}">
                    <div data-switch-panel="code" id="confirm-code">
                        <x-ui.otp-input
                            script="vanilla"
                            :label="__('Six-digit code from your authenticator app')"
                            id="code"
                            name="code"
                            :hint="__('A code that was just used to log in is refused. Wait for the next one.')"
                        />
                        <p class="mt-3 text-center text-sm text-muted-foreground">
                            <a href="#confirm-recovery" data-switch-to="recovery" class="rounded-sm font-medium text-foreground underline underline-offset-4 hover:text-primary">{{ __('Use a recovery code instead') }}</a>
                        </p>
                    </div>

                    <div data-switch-panel="recovery" id="confirm-recovery" class="border-t border-border pt-5 group-data-enhanced/switch:border-0 group-data-enhanced/switch:pt-0">
                        <x-ui.input :label="__('Recovery code')" name="recovery_code" icon="key" autocomplete="off" autocapitalize="characters" spellcheck="false" class="font-mono" :hint="__('Leave the six-digit code empty. The recovery code works only once.')" />
                        <p class="mt-3 text-sm text-muted-foreground">
                            <a href="#confirm-code" data-switch-to="code" class="rounded-sm font-medium text-foreground underline underline-offset-4 hover:text-primary">{{ __('Use the code from your app instead') }}</a>
                        </p>
                    </div>
                </div>

                <x-ui.submit-button>{{ __('Confirm') }}</x-ui.submit-button>
            </form>
        </x-ui.card>

        <p class="text-center">
            <x-ui.button :href="route('security.index')" variant="link">{{ __('Cancel') }}</x-ui.button>
        </p>
    </x-ui.page>
</x-layouts::app>
