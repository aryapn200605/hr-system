<x-ui.page :title="$title" :icon="$type === 'leave' ? 'calendar' : 'clock'" :back="['href' => route('history.index'), 'label' => __('Back to request history')]">
    <x-slot:subtitle>
        <span class="flex flex-wrap items-center gap-2">
            <x-ui.badge :tone="$record->status->tone()" dot>{{ $record->status->label() }}</x-ui.badge>
            <span class="wrap-anywhere">{{ $requester->name }} · {{ __('Submitted') }} <x-ui.datetime :value="$record->created_at" /></span>
        </span>
    </x-slot:subtitle>

    <div class="flex items-center gap-3 rounded-xl border border-primary/20 bg-primary/5 px-4 py-3 text-sm text-primary-hover" role="status" data-view-only>
        <x-ui.icon-chip icon="eye" size="sm" />
        <p>{{ __('You are viewing this request. You cannot decide it here.') }}</p>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Details')" icon="file-text">
            <dl class="divide-y divide-border px-5 py-2">
                <x-ui.detail :label="__('Requester')">
                    {{ $requester->name }}
                    @if ($requester->department)
                        <span class="text-muted-foreground">· {{ $requester->department->name }}</span>
                    @endif
                </x-ui.detail>
                <x-ui.detail :label="__('Role')"><x-ui.role-badge :user="$requester" /></x-ui.detail>
                <x-ui.detail :label="__('Status')"><x-ui.badge :tone="$record->status->tone()" dot data-status>{{ $record->status->label() }}</x-ui.badge></x-ui.detail>

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
                        <a href="{{ route('requests.evidence', ['type' => $type, 'id' => $record->id]) }}" class="text-primary underline underline-offset-2 hover:text-primary-hover" data-evidence-link>{{ $record->evidence_name }}</a>
                    @else
                        <span data-evidence-restricted>{{ __('Attached. Only the requester and those who may decide can download it.') }}</span>
                    @endif
                </x-ui.detail>
            </dl>
        </x-ui.card>

        <div class="space-y-6">
            @include('livewire.requests.partials.progress', ['record' => $record])

            <x-ui.card :title="__('Reason')" icon="list" tone="gray">
                <p class="px-5 py-4 text-sm whitespace-pre-line wrap-anywhere text-foreground">{{ $record->reason }}</p>
            </x-ui.card>

            @if ($record->decided_at)
                @include('livewire.requests.partials.decision', ['record' => $record])
            @endif
        </div>
    </div>

    @if ($record instanceof \App\Models\LeaveRequest && $record->allocations->isNotEmpty())
        <x-ui.card :title="__('Leave balance used per period')" icon="wallet" tone="green">
            @include('livewire.requests.partials.allocations', ['record' => $record])
        </x-ui.card>
    @endif
</x-ui.page>
