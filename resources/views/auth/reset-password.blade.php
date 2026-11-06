<x-layouts::auth :title="__('Reset password')">
    <x-ui.auth-heading icon="lock" :title="__('Choose a new password')">{{ __('Enter the email address of your account and a new password. You will still need your authenticator app to log in.') }}</x-ui.auth-heading>

    <form method="POST" action="{{ route('password.update') }}" class="grid gap-4">
        @csrf

        <x-ui.input :label="__('Email')" name="email" type="email" icon="mail" required autofocus autocomplete="email" autocapitalize="none" spellcheck="false" />

        <x-ui.password-input :label="__('New password')" name="password" required autocomplete="new-password" described-by="password-rules" />

        @include('auth.partials.password-rules', ['id' => 'password-rules'])

        <x-ui.password-input :label="__('Confirm new password')" name="password_confirmation" required autocomplete="new-password" />

        <x-ui.submit-button class="mt-2">{{ __('Change password') }}</x-ui.submit-button>
    </form>

    <p class="mt-6 text-center text-sm">
        <a href="{{ route('login') }}" class="rounded-sm font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline">{{ __('Back to login') }}</a>
    </p>
</x-layouts::auth>
