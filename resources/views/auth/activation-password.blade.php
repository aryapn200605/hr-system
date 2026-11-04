<x-layouts::auth :title="__('Activate your account')">
    <x-ui.auth-heading :title="__('Activate your account')">{{ __('Step 1 of 2: choose a password of at least 12 characters, with uppercase and lowercase letters and a digit.') }}</x-ui.auth-heading>

    <form method="POST" action="{{ route('activation.password.store') }}" class="space-y-4">
        @csrf

        <x-ui.input :label="__('Password')" name="password" type="password" required autofocus autocomplete="new-password" />
        <x-ui.input :label="__('Confirm password')" name="password_confirmation" type="password" required autocomplete="new-password" />

        <x-ui.button type="submit" block>{{ __('Continue') }}</x-ui.button>
    </form>
</x-layouts::auth>
