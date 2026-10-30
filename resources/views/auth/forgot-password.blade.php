<x-layouts::auth title="Forgot password">
    <x-ui.auth-heading title="Forgot your password?">Enter your email address. If it can reset a password, we will send a link there.</x-ui.auth-heading>

    @if (session('status'))
        <x-ui.alert tone="success" class="mb-4">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <x-ui.input label="Email" name="email" type="email" required autofocus autocomplete="email" />

        <x-ui.button type="submit" block>Send reset link</x-ui.button>
    </form>

    <div class="mt-6">
        <x-ui.button variant="link" :href="route('login')">Back to login</x-ui.button>
    </div>
</x-layouts::auth>
