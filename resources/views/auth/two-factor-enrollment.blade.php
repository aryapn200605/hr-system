<x-layouts::auth :title="__('Set up two-factor authentication')">
    <x-ui.steps :steps="[__('Authenticator'), __('Recovery codes')]" :current="1" />

    <x-ui.auth-heading icon="shield" :title="__('Set up two-factor authentication')">{{ __('Your account needs an authenticator app before you can continue.') }}</x-ui.auth-heading>

    @include('auth.partials.two-factor-setup', ['action' => route('two-factor.enrollment.store')])

    <form method="POST" action="{{ route('two-factor.cancel') }}" class="border-t border-border pt-4 text-center">
        @csrf
        <x-ui.button type="submit" variant="link">{{ __('Cancel and go back to login') }}</x-ui.button>
    </form>
</x-layouts::auth>
