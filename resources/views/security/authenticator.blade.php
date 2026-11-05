<x-layouts::app :title="__('Reconfigure authenticator')" :breadcrumbs="[
    ['label' => __('Home'), 'href' => route('dashboard')],
    ['label' => __('Security Settings'), 'href' => route('security.index')],
    ['label' => __('Reconfigure authenticator')],
]">
    <x-ui.page width="narrow" :title="__('Reconfigure authenticator')" :subtitle="__('Scan this new code. Your current authenticator keeps working until a code from the new one is confirmed. Your recovery codes are then replaced and other sessions end.')">

        <x-ui.card class="p-5">
            @include('auth.partials.two-factor-setup', ['action' => route('security.authenticator.store')])
        </x-ui.card>

        <form method="POST" action="{{ route('security.authenticator.cancel') }}" class="text-center">
            @csrf

            <x-ui.button type="submit" variant="link">{{ __('Cancel') }}</x-ui.button>
        </form>
    </x-ui.page>
</x-layouts::app>
