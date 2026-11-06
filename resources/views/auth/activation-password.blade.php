<x-layouts::auth :title="__('Activate your account')">
    @include('auth.partials.activation-steps', ['current' => 1])

    <x-ui.auth-heading icon="lock" :title="__('Activate your account')">{{ __('Choose the password you will log in with. The next step sets up your authenticator app.') }}</x-ui.auth-heading>

    <form method="POST" action="{{ route('activation.password.store') }}" class="grid gap-4">
        @csrf

        <x-ui.password-input :label="__('Password')" name="password" required autofocus autocomplete="new-password" described-by="password-rules" />

        @include('auth.partials.password-rules', ['id' => 'password-rules'])

        <x-ui.password-input :label="__('Confirm password')" name="password_confirmation" required autocomplete="new-password" />

        <x-ui.submit-button class="mt-2">{{ __('Continue') }}</x-ui.submit-button>
    </form>
</x-layouts::auth>
