<div class="space-y-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $title }}</h1>
            <p class="mt-2 text-sm text-slate-600">{{ __('Submitted') }} <x-ui.datetime :value="$record->created_at" /></p>
        </div>

        <div class="flex shrink-0 flex-wrap gap-2 self-start">
            <x-ui.button variant="secondary" size="sm" :href="route('requests.index', $type === 'leave' ? [] : ['type' => 'overtime'])">{{ __('Back to my requests') }}</x-ui.button>
            @if ($canCancel)
                <x-ui.button variant="secondary" size="sm" class="text-red-800!" wire:click="openCancel" loading="openCancel" data-cancel-request>{{ __('Cancel request') }}</x-ui.button>
            @endif
        </div>
    </header>

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Details')">
            <dl class="divide-y divide-slate-200 px-5 py-2">
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
                    @if ($record->hasEvidence())
                        <a href="{{ route('requests.evidence', ['type' => $type, 'id' => $record->id]) }}" class="text-blue-700 underline underline-offset-2 hover:text-blue-900" data-evidence-link>{{ $record->evidence_name }}</a>
                    @else
                        {{ __('None') }}
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

    <x-ui.modal name="request-cancel" :title="__('Cancel this request?')">
        <p>{{ __('The request will no longer be decided, and its dates become free again. This cannot be undone.') }}</p>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Keep request') }}</x-ui.button>
            <x-ui.button variant="danger" wire:click="cancel" loading="cancel">{{ __('Cancel request') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
