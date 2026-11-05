<x-ui.page :title="$title">
    <x-slot:subtitle><span class="wrap-anywhere">{{ $requester->name }} · {{ __('Submitted') }} <x-ui.datetime :value="$record->created_at" /></span></x-slot:subtitle>
    <x-slot:actions>
        <x-ui.button variant="secondary" size="sm" :href="route('history.index')" data-back>{{ __('Back to request history') }}</x-ui.button>
    </x-slot:actions>

    <div class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2.5 text-sm text-blue-900" role="status" data-view-only>
        <p>{{ __('You are viewing this request. You cannot decide it here.') }}</p>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Details')">
            <dl class="divide-y divide-slate-200 px-5 py-2">
                <x-ui.detail :label="__('Requester')">
                    {{ $requester->name }}
                    @if ($requester->department)
                        <span class="text-slate-600">· {{ $requester->department->name }}</span>
                    @endif
                </x-ui.detail>
                <x-ui.detail :label="__('Role')"><x-ui.role-badge :user="$requester" /></x-ui.detail>
                <x-ui.detail :label="__('Status')"><x-ui.badge :tone="$record->status->tone()" data-status>{{ $record->status->label() }}</x-ui.badge></x-ui.detail>

                @if ($record instanceof \App\Models\LeaveRequest)
                    <x-ui.detail :label="__('Category')">{{ $record->category->label() }}</x-ui.detail>
                    <x-ui.detail :label="__('Dates')"><x-ui.date :value="$record->starts_on" /> – <x-ui.date :value="$record->ends_on" /></x-ui.detail>
                    <x-ui.detail :label="__('Working days')">{{ $record->working_days }}</x-ui.detail>
                    <x-ui.detail :label="__('Uses leave balance')">{{ $record->deducts_balance ? __('Yes') : __('No') }}</x-ui.detail>
                @else
                    <x-ui.detail :label="__('Start')"><x-ui.datetime :value="$record->start_at" /></x-ui.detail>
                    <x-ui.detail :label="__('End')"><x-ui.datetime :value="$record->end_at" /></x-ui.detail>
                    <x-ui.detail :label="__('Hours')">{{ $record->hours }}</x-ui.detail>
                @endif

                @if ($record->cancelled_at)
                    <x-ui.detail :label="__('Cancelled')"><x-ui.datetime :value="$record->cancelled_at" /></x-ui.detail>
                @endif

                <x-ui.detail :label="__('Evidence')">
                    @if (! $record->hasEvidence())
                        {{ __('None') }}
                    @elseif ($canDownload)
                        <a href="{{ route('requests.evidence', ['type' => $type, 'id' => $record->id]) }}" class="text-blue-700 underline underline-offset-2 hover:text-blue-900" data-evidence-link>{{ $record->evidence_name }}</a>
                    @else
                        <span data-evidence-restricted>{{ __('Attached. Only the requester and those who may decide can download it.') }}</span>
                    @endif
                </x-ui.detail>
            </dl>
        </x-ui.card>

        <div class="space-y-6">
            <x-ui.card :title="__('Reason')">
                <p class="px-5 py-4 text-sm whitespace-pre-line wrap-anywhere text-slate-800">{{ $record->reason }}</p>
            </x-ui.card>

            @if ($record->decided_at)
                @include('livewire.requests.partials.decision', ['record' => $record])
            @endif
        </div>
    </div>

    @if ($record instanceof \App\Models\LeaveRequest && $record->allocations->isNotEmpty())
        <x-ui.card :title="__('Leave balance used per period')">
            @include('livewire.requests.partials.allocations', ['record' => $record])
        </x-ui.card>
    @endif
</x-ui.page>
