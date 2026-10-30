<x-layouts::app title="Reconfigure authenticator" :breadcrumbs="[
    ['label' => 'Home', 'href' => route('dashboard')],
    ['label' => 'Security Settings', 'href' => route('security.index')],
    ['label' => 'Reconfigure authenticator'],
]">
    <div class="max-w-md space-y-6">
        @include('security.partials.page-header', [
            'title' => 'Reconfigure authenticator',
            'description' => 'Scan this new code. Your current authenticator keeps working until a code from the new one is confirmed. Your recovery codes are then replaced and other sessions end.',
        ])

        <x-ui.card class="pt-4">
            @include('auth.partials.two-factor-setup', ['action' => route('security.authenticator.store')])
        </x-ui.card>

        <form method="POST" action="{{ route('security.authenticator.cancel') }}">
            @csrf

            <x-ui.button type="submit" variant="link">Cancel</x-ui.button>
        </form>
    </div>
</x-layouts::app>
