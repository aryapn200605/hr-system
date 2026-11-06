<x-layouts::auth :title="__('Forgot password')">
    <x-ui.auth-heading icon="mail" :title="__('Forgot your password?')">{{ __('Enter your email address. If it can reset a password, we will send a link there.') }}</x-ui.auth-heading>

    @if (session('status'))
        <x-ui.alert tone="success" class="mb-4">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="grid gap-4">
        @csrf

        <x-ui.input :label="__('Email')" name="email" type="email" icon="mail" required autofocus autocomplete="email" autocapitalize="none" spellcheck="false" />

        <x-ui.submit-button class="mt-2">{{ __('Send reset link') }}</x-ui.submit-button>
    </form>

    <p class="mt-6 text-center text-sm">
        <a href="{{ route('login') }}" class="rounded-sm font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline">{{ __('Back to login') }}</a>
    </p>
</x-layouts::auth>
