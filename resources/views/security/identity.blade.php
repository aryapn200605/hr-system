<x-layouts::app :title="__('Change username or email')" :breadcrumbs="[
    ['label' => __('Home'), 'href' => route('dashboard')],
    ['label' => __('My Profile'), 'href' => route('profile.show')],
    ['label' => __('Change username or email')],
]">
    <div class="mx-auto max-w-md space-y-6">
        @include('security.partials.page-header', [
            'title' => __('Change username or email'),
            'description' => __('You log in with either one, and password reset links go to your email.'),
        ])

        <x-ui.alert tone="warning">{{ __('No email is sent to confirm a new address. Check it carefully: reset links will go only to this address.') }}</x-ui.alert>

        <x-ui.card class="p-5">
            <form method="POST" action="{{ route('profile.identity.update') }}" class="space-y-4">
                @csrf

                <x-ui.input :label="__('Username')" name="username" required autocomplete="username" :value="old('username', $user->username)" />
                <x-ui.input :label="__('Email')" name="email" type="email" required autocomplete="email" :value="old('email', $user->email)" />
                <x-ui.input :label="__('Confirm email')" name="email_confirmation" type="email" required autocomplete="off" :hint="__('To change your email, type the new address in both fields.')" :value="old('email_confirmation', $user->email)" />

                <x-ui.button type="submit" block>{{ __('Save changes') }}</x-ui.button>
            </form>
        </x-ui.card>

        <p class="text-center">
            <x-ui.button :href="route('profile.show')" variant="link">{{ __('Cancel') }}</x-ui.button>
        </p>
    </div>
</x-layouts::app>
