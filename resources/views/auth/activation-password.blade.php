<x-layouts::auth title="Activate your account">
    <x-ui.auth-heading title="Activate your account">Step 1 of 2: choose a password of at least 12 characters, with uppercase and lowercase letters and a digit.</x-ui.auth-heading>

    <form method="POST" action="{{ route('activation.password.store') }}" class="space-y-4">
        @csrf

        <x-ui.input label="Password" name="password" type="password" required autofocus autocomplete="new-password" />
        <x-ui.input label="Confirm password" name="password_confirmation" type="password" required autocomplete="new-password" />

        <x-ui.button type="submit" block>Continue</x-ui.button>
    </form>
</x-layouts::auth>
