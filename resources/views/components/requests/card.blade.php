@props(['request', 'evidenceUrl'])

<x-ui.card>
    <dl class="divide-y divide-rule">
        {{ $slot }}
        <x-ui.detail label="Status"><x-ui.badge :tone="$request->status->tone()">{{ $request->status->label() }}</x-ui.badge></x-ui.detail>
        <x-ui.detail label="Reason">{{ $request->reason }}</x-ui.detail>
        <x-ui.detail label="Evidence">
            @if ($request->hasEvidence())
                <x-ui.button variant="link" :href="$evidenceUrl">Download evidence</x-ui.button>
            @else
                None
            @endif
        </x-ui.detail>
        <x-ui.detail label="Submitted"><x-ui.datetime :value="$request->created_at" /></x-ui.detail>
        @if ($request->cancelled_at)
            <x-ui.detail label="Cancelled"><x-ui.datetime :value="$request->cancelled_at" /></x-ui.detail>
        @endif
    </dl>

    @if ($request->isPending())
        <x-slot:footer>
            <x-ui.button variant="danger" size="sm" x-on:click="$dispatch('open-modal', { name: 'request-cancel' })">Cancel request</x-ui.button>
        </x-slot:footer>
    @endif
</x-ui.card>

{{-- Stays in the page when the request is decided: removing an open dialog would leave its focus trap holding every click. --}}
<x-ui.modal name="request-cancel" title="Cancel this request?">
    <p>The request stays in your list as cancelled, and its dates are free again.</p>

    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="open = false">Keep request</x-ui.button>
        <x-ui.button variant="danger" wire:click="cancel" loading="cancel">Cancel request</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
