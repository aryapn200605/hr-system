{{-- The decision on a request, for the requester and for deciders. Needs $record with its decider loaded. --}}
<x-ui.card :title="__('Decision')" data-decision>
    <dl class="divide-y divide-slate-200 px-5 py-2">
        <x-ui.detail :label="__('Decided by')"><span data-decided-by>{{ $record->decider?->name ?? __('a removed user') }}</span></x-ui.detail>
        <x-ui.detail :label="__('Decided')"><x-ui.datetime :value="$record->decided_at" /></x-ui.detail>
        @if ($record->decision_note !== null)
            <x-ui.detail :label="$record->status === \App\Enums\RequestStatus::Rejected ? __('Reason for rejection') : __('Note')">
                <span class="whitespace-pre-line wrap-anywhere" data-decision-note>{{ $record->decision_note }}</span>
            </x-ui.detail>
        @endif
    </dl>
</x-ui.card>
