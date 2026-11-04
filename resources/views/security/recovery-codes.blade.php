<x-layouts::app :title="__('Regenerate recovery codes')" :breadcrumbs="[
    ['label' => __('Home'), 'href' => route('dashboard')],
    ['label' => __('Security Settings'), 'href' => route('security.index')],
    ['label' => __('Regenerate recovery codes')],
]">
    <div class="mx-auto max-w-md space-y-6">
        @include('security.partials.page-header', ['title' => __('Regenerate recovery codes')])

        <x-ui.alert tone="warning">{{ __('Every existing recovery code stops working at once. The new codes are shown only one time.') }}</x-ui.alert>

        <form method="POST" action="{{ route('security.recovery-codes.store') }}">
            @csrf

            <x-ui.button type="submit" variant="danger" block>{{ __('Regenerate recovery codes') }}</x-ui.button>
        </form>

        <p class="text-center">
            <x-ui.button :href="route('security.index')" variant="link">{{ __('Cancel') }}</x-ui.button>
        </p>
    </div>
</x-layouts::app>
