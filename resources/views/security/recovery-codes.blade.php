<x-layouts::app title="Regenerate recovery codes" :breadcrumbs="[
    ['label' => 'Home', 'href' => route('dashboard')],
    ['label' => 'Security Settings', 'href' => route('security.index')],
    ['label' => 'Regenerate recovery codes'],
]">
    <div class="max-w-md space-y-6">
        @include('security.partials.page-header', ['title' => 'Regenerate recovery codes'])

        <x-ui.alert tone="warning">Every existing recovery code stops working at once. The new codes are shown only one time.</x-ui.alert>

        <form method="POST" action="{{ route('security.recovery-codes.store') }}">
            @csrf

            <x-ui.button type="submit" variant="danger" block>Regenerate recovery codes</x-ui.button>
        </form>

        <p>
            <x-ui.button :href="route('security.index')" variant="link">Cancel</x-ui.button>
        </p>
    </div>
</x-layouts::app>
