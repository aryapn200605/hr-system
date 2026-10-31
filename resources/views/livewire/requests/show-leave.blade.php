<div class="space-y-6">
    <x-ui.page-header title="Leave request">
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" :href="route('requests.index')">Back to my requests</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    <x-requests.card :request="$request" :evidence-url="route('requests.leave.evidence', $request->id)">
        <x-ui.detail label="Category">{{ $request->category->name }}</x-ui.detail>
        <x-ui.detail label="First day">{{ $request->start_date->format('j M Y') }}</x-ui.detail>
        <x-ui.detail label="Last day">{{ $request->end_date->format('j M Y') }}</x-ui.detail>
        <x-ui.detail label="Working days">{{ $request->working_days }}</x-ui.detail>
    </x-requests.card>
</div>
