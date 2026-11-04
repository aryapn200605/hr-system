<x-layouts::app :title="__('Change password')" :breadcrumbs="[
    ['label' => __('Home'), 'href' => route('dashboard')],
    ['label' => __('Security Settings'), 'href' => route('security.index')],
    ['label' => __('Change password')],
]">
    <div class="mx-auto max-w-md space-y-6">
        @include('security.partials.page-header', [
            'title' => __('Change password'),
            'description' => __('All your other sessions will end. This one stays logged in.'),
        ])

        <x-ui.card class="p-5">
            <form method="POST" action="{{ route('security.password.update') }}" class="space-y-4">
                @csrf

                <x-ui.input :label="__('New password')" name="password" type="password" required autofocus autocomplete="new-password" :hint="__('At least 12 characters, with uppercase, lowercase and a digit.')" />
                <x-ui.input :label="__('Confirm new password')" name="password_confirmation" type="password" required autocomplete="new-password" />

                <x-ui.button type="submit" block>{{ __('Change password') }}</x-ui.button>
            </form>
        </x-ui.card>

        <p class="text-center">
            <x-ui.button :href="route('security.index')" variant="link">{{ __('Cancel') }}</x-ui.button>
        </p>
    </div>
</x-layouts::app>
