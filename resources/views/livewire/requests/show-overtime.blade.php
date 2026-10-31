<div class="space-y-6">
    <x-ui.page-header title="Overtime request">
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" :href="route('requests.index')">Back to my requests</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    <x-requests.card :request="$request" :evidence-url="route('requests.overtime.evidence', $request->id)">
        <x-ui.detail label="Start">{{ $request->start_at->format('j M Y, H:i') }} WIB</x-ui.detail>
        <x-ui.detail label="End">{{ $request->end_at->format('j M Y, H:i') }} WIB</x-ui.detail>
        <x-ui.detail label="Hours">{{ $request->hours }}</x-ui.detail>
    </x-requests.card>
</div>
