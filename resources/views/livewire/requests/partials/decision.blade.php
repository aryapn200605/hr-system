{{-- The decision on a request, for the requester and for deciders. Needs $record with its decider loaded. --}}
<x-ui.card :title="__('Decision')" :icon="$record->status === \App\Enums\RequestStatus::Approved ? 'check-circle' : 'x-circle'" :tone="$record->status->tone()" data-decision>
    <dl class="divide-y divide-border px-5 py-2">
        <x-ui.detail :label="__('Decided by')"><span data-decided-by>{{ $record->deciderLabel() }}</span></x-ui.detail>
        <x-ui.detail :label="__('Decided')"><x-ui.datetime :value="$record->decided_at" /></x-ui.detail>
        @if (($text = $record->decisionText()) !== null)
            <x-ui.detail :label="$record->status === \App\Enums\RequestStatus::Rejected ? __('Reason for rejection') : __('Note')">
                <span class="whitespace-pre-line wrap-anywhere" data-decision-note>{{ $text }}</span>
            </x-ui.detail>
        @endif
    </dl>
</x-ui.card>
