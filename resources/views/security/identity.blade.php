<x-layouts::app :title="__('Change username or email')" :breadcrumbs="[
    ['label' => __('Home'), 'href' => route('dashboard')],
    ['label' => __('My Profile'), 'href' => route('profile.show')],
    ['label' => __('Change username or email')],
]">
    {{-- A controller page: the auth pages' submit state (plain JavaScript). Without JavaScript the form is unchanged. --}}
    @vite('resources/js/auth.js')

    <x-ui.page width="narrow" icon="mail" :title="__('Change username or email')" :subtitle="__('You log in with either one, and password reset links go to your email.')">

        <x-ui.alert tone="warning">{{ __('No email is sent to confirm a new address. Check it carefully: reset links will go only to this address.') }}</x-ui.alert>

        <x-ui.card class="p-5 sm:p-6" data-card>
            <form method="POST" action="{{ route('profile.identity.update') }}" class="space-y-4">
                @csrf

                <x-ui.input :label="__('Username')" name="username" icon="user" required autocomplete="username" :value="old('username', $user->username)" />
                <x-ui.input :label="__('Email')" name="email" type="email" icon="mail" required autocomplete="email" :value="old('email', $user->email)" />
                <x-ui.input :label="__('Confirm email')" name="email_confirmation" type="email" icon="mail" required autocomplete="off" :hint="__('To change your email, type the new address in both fields.')" :value="old('email_confirmation', $user->email)" />

                <x-ui.submit-button>{{ __('Save changes') }}</x-ui.submit-button>
            </form>
        </x-ui.card>

        <p class="text-center">
            <x-ui.button :href="route('profile.show')" variant="link">{{ __('Cancel') }}</x-ui.button>
        </p>
    </x-ui.page>
</x-layouts::app>
