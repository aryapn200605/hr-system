<x-layouts::auth title="Activate your account">
    <x-ui.auth-heading title="Set up two-factor authentication">Step 2 of 2: scan this code with Google Authenticator or Microsoft Authenticator. Your account stays inactive until this step succeeds.</x-ui.auth-heading>

    @include('auth.partials.two-factor-setup', ['action' => route('activation.two-factor.store')])
</x-layouts::auth>
