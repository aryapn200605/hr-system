<x-layouts::auth :title="__('Reset password')">
    <x-ui.auth-heading :title="__('Choose a new password')">{{ __('Use at least 12 characters, with uppercase and lowercase letters and a digit. You will still need your authenticator app to log in.') }}</x-ui.auth-heading>

    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf

        <x-ui.input :label="__('Email')" name="email" type="email" required autofocus autocomplete="email" />
        <x-ui.input :label="__('New password')" name="password" type="password" required autocomplete="new-password" />
        <x-ui.input :label="__('Confirm new password')" name="password_confirmation" type="password" required autocomplete="new-password" />

        <x-ui.button type="submit" block>{{ __('Change password') }}</x-ui.button>
    </form>
</x-layouts::auth>
