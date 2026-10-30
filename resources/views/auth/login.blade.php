<x-layouts::auth title="Log in">
    <x-ui.auth-heading title="Log in" />

    @if (session('status'))
        <x-ui.alert tone="success" class="mb-4">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf

        <x-ui.input label="Username or email" name="identifier" required autofocus autocomplete="username" />
        <x-ui.input label="Password" name="password" type="password" required autocomplete="current-password" />

        <x-ui.button type="submit" block>Continue</x-ui.button>
    </form>

    <div class="mt-6">
        <x-ui.button variant="link" :href="route('password.request')">Forgot your password?</x-ui.button>
    </div>
</x-layouts::auth>
