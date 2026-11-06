<x-layouts::auth :title="__('Log in')">
    <x-ui.auth-heading icon="lock" :title="__('Log in')">{{ __('Enter your username or email and your password. The next step asks for a code from your authenticator app.') }}</x-ui.auth-heading>

    @if (session('status'))
        <x-ui.alert tone="success" class="mb-4">{{ session('status') }}</x-ui.alert>
    @endif

    {{-- The identifier is never filled back after a failed login (an existing decision): no old(). --}}
    <form method="POST" action="{{ route('login.store') }}" class="grid gap-4">
        @csrf

        <x-ui.input :label="__('Username or email')" name="identifier" icon="user" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" />

        <x-ui.password-input :label="__('Password')" name="password" required autocomplete="current-password">
            <x-slot:action>
                <a href="{{ route('password.request') }}" class="rounded-sm text-sm font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline">{{ __('Forgot your password?') }}</a>
            </x-slot:action>
        </x-ui.password-input>

        <x-ui.submit-button icon="log-in" class="mt-2">{{ __('Log in') }}</x-ui.submit-button>
    </form>
</x-layouts::auth>
