<x-layouts::auth title="Reset password">
    <x-ui.auth-heading title="Choose a new password">Use at least 12 characters, with uppercase and lowercase letters and a digit. You will still need your authenticator app to log in.</x-ui.auth-heading>

    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf

        <x-ui.input label="Email" name="email" type="email" required autofocus autocomplete="email" />
        <x-ui.input label="New password" name="password" type="password" required autocomplete="new-password" />
        <x-ui.input label="Confirm new password" name="password_confirmation" type="password" required autocomplete="new-password" />

        <x-ui.button type="submit" block>Change password</x-ui.button>
    </form>
</x-layouts::auth>
