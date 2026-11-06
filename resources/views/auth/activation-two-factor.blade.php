<x-layouts::auth :title="__('Activate your account')">
    @include('auth.partials.activation-steps', ['current' => 2])

    <x-ui.auth-heading icon="shield" :title="__('Set up two-factor authentication')">{{ __('Your account stays inactive until this step succeeds.') }}</x-ui.auth-heading>

    @include('auth.partials.two-factor-setup', ['action' => route('activation.two-factor.store')])
</x-layouts::auth>
