<x-layouts::app title="Change username or email" :breadcrumbs="[
    ['label' => 'Home', 'href' => route('dashboard')],
    ['label' => 'My Profile', 'href' => route('profile.show')],
    ['label' => 'Change username or email'],
]">
    <div class="max-w-md space-y-6">
        @include('security.partials.page-header', [
            'title' => 'Change username or email',
            'description' => 'You log in with either one, and password reset links go to your email.',
        ])

        <x-ui.alert tone="warning">No email is sent to confirm a new address. Check it carefully: reset links will go only to this address.</x-ui.alert>

        <x-ui.card class="pt-4">
            <form method="POST" action="{{ route('profile.identity.update') }}" class="space-y-4">
                @csrf

                <x-ui.input label="Username" name="username" required autocomplete="username" :value="old('username', $user->username)" />
                <x-ui.input label="Email" name="email" type="email" required autocomplete="email" :value="old('email', $user->email)" />
                <x-ui.input label="Confirm email" name="email_confirmation" type="email" required autocomplete="off" hint="To change your email, type the new address in both fields." :value="old('email_confirmation', $user->email)" />

                <x-ui.button type="submit" block>Save changes</x-ui.button>
            </form>
        </x-ui.card>

        <p>
            <x-ui.button :href="route('profile.show')" variant="link">Cancel</x-ui.button>
        </p>
    </div>
</x-layouts::app>
