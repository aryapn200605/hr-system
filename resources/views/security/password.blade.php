<x-layouts::app title="Change password" :breadcrumbs="[
    ['label' => 'Home', 'href' => route('dashboard')],
    ['label' => 'Security Settings', 'href' => route('security.index')],
    ['label' => 'Change password'],
]">
    <div class="mx-auto w-full max-w-md space-y-6">
        @include('security.partials.page-header', [
            'title' => 'Change password',
            'description' => 'All your other sessions will end. This one stays logged in.',
        ])

        <x-ui.card class="pt-4">
            <form method="POST" action="{{ route('security.password.update') }}" class="space-y-4">
                @csrf

                <x-ui.input label="New password" name="password" type="password" required autofocus autocomplete="new-password" hint="At least 12 characters, with uppercase, lowercase and a digit." />
                <x-ui.input label="Confirm new password" name="password_confirmation" type="password" required autocomplete="new-password" />

                <x-ui.button type="submit" block>Change password</x-ui.button>
            </form>
        </x-ui.card>

        <p>
            <x-ui.button :href="route('security.index')" variant="link">Cancel</x-ui.button>
        </p>
    </div>
</x-layouts::app>
