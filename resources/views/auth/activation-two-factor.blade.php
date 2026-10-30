<x-layouts::app title="Activate your account">
    <h1 class="mb-2 text-xl font-semibold">Set up two-factor authentication</h1>
    <p class="mb-6 text-sm">Step 2 of 2: scan this code with Google Authenticator or Microsoft Authenticator. Your account stays inactive until this step succeeds.</p>

    @include('auth.partials.two-factor-setup', ['action' => route('activation.two-factor.store')])
</x-layouts::app>
