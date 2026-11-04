<x-layouts::auth :title="__('Forgot password')">
    <x-ui.auth-heading :title="__('Forgot your password?')">{{ __('Enter your email address. If it can reset a password, we will send a link there.') }}</x-ui.auth-heading>

    @if (session('status'))
        <x-ui.alert tone="success" class="mb-4">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <x-ui.input :label="__('Email')" name="email" type="email" required autofocus autocomplete="email" />

        <x-ui.button type="submit" block>{{ __('Send reset link') }}</x-ui.button>
    </form>

    <div class="mt-6 text-center">
        <x-ui.button variant="link" :href="route('login')">{{ __('Back to login') }}</x-ui.button>
    </div>
</x-layouts::auth>
