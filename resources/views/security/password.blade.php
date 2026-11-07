<x-layouts::app :title="__('Change password')" :breadcrumbs="[
    ['label' => __('Home'), 'href' => route('dashboard')],
    ['label' => __('Security Settings'), 'href' => route('security.index')],
    ['label' => __('Change password')],
]">
    {{-- A controller page, like the confirmation page: the auth pages' plain-JavaScript fields (show/hide password,
         submit state). Without JavaScript the form is unchanged. --}}
    @vite('resources/js/auth.js')

    <x-ui.page width="narrow" icon="lock" :title="__('Change password')" :subtitle="__('All your other sessions will end. This one stays logged in.')">

        <x-ui.card class="p-5 sm:p-6" data-card>
            <form method="POST" action="{{ route('security.password.update') }}" class="space-y-4">
                @csrf

                <x-ui.password-input :label="__('New password')" name="password" required autofocus autocomplete="new-password" :hint="__('At least 12 characters, with uppercase, lowercase and a digit.')" />
                <x-ui.password-input :label="__('Confirm new password')" name="password_confirmation" required autocomplete="new-password" />

                <x-ui.submit-button>{{ __('Change password') }}</x-ui.submit-button>
            </form>
        </x-ui.card>

        <p class="text-center">
            <x-ui.button :href="route('security.index')" variant="link">{{ __('Cancel') }}</x-ui.button>
        </p>
    </x-ui.page>
</x-layouts::app>
