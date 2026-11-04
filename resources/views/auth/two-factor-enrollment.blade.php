<x-layouts::auth :title="__('Set up two-factor authentication')">
    <x-ui.auth-heading :title="__('Set up two-factor authentication')">{{ __('Your account needs an authenticator app. Scan this code with Google Authenticator or Microsoft Authenticator.') }}</x-ui.auth-heading>

    @include('auth.partials.two-factor-setup', ['action' => route('two-factor.enrollment.store')])

    <form method="POST" action="{{ route('two-factor.cancel') }}" class="mt-6 text-center">
        @csrf
        <x-ui.button type="submit" variant="link">{{ __('Cancel') }}</x-ui.button>
    </form>
</x-layouts::auth>
