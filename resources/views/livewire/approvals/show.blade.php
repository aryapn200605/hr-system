<div
    @if ($record !== null && $holdsLock) wire:poll.{{ $heartbeat }}s="heartbeat" @elseif ($record !== null && $viewOnly) wire:poll.30s @endif
    @if ($record !== null)
        {{-- Closing the tab or leaving the page lets go of the lock at once; a Livewire call would not survive the unload. --}}
        x-data
        x-on:pagehide.window="if ($wire.holdsLock) navigator.sendBeacon({{ \Illuminate\Support\Js::from(route('approvals.release', ['type' => $type, 'id' => $requestId])) }}, new URLSearchParams({ _token: {{ \Illuminate\Support\Js::from(csrf_token()) }} }))"
    @endif
>
<x-ui.page :title="$title" :icon="$type === 'leave' ? 'calendar' : 'clock'">
    @if ($record !== null)
        <x-slot:subtitle>
            <span class="flex flex-wrap items-center gap-2">
                <x-ui.badge :tone="$record->status->tone()" dot>{{ $record->status->label() }}</x-ui.badge>
                <span>{{ $requester->name }} · {{ __('Submitted') }} <x-ui.datetime :value="$record->created_at" /></span>
            </span>
        </x-slot:subtitle>
    @endif

    {{-- "Back" is a Livewire action, not a link: it lets go of the review lock before leaving. --}}
    <x-slot:actions>
        <x-ui.button variant="secondary" size="sm" wire:click="close" loading="close" data-close><x-ui.icon name="arrow-left" class="size-4" wire:loading.remove wire:target="close" />{{ __('Back to approvals') }}</x-ui.button>
        @if ($record !== null && $canDecide)
            <x-ui.button variant="secondary" size="sm" class="text-red-800!" wire:click="openReject" loading="openReject" data-reject><x-ui.icon name="x-circle" class="size-4" wire:loading.remove wire:target="openReject" />{{ __('Reject') }}</x-ui.button>
            <x-ui.button size="sm" wire:click="openApprove" loading="openApprove" data-approve><x-ui.icon name="check" class="size-4" wire:loading.remove wire:target="openApprove" />{{ __('Approve') }}</x-ui.button>
        @endif
    </x-slot:actions>

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    @if ($record !== null)
        @if ($viewOnly)
            <div class="flex items-start gap-3 rounded-xl border border-primary/20 bg-primary/5 px-4 py-3 text-sm text-primary-hover" role="status" data-view-only>
                <x-ui.icon-chip icon="eye" size="sm" />
                <p class="pt-1.5">{{ $heldMessage }}</p>
            </div>
        @elseif ($outcome !== null)
            <x-ui.alert data-outcome>{{ $outcome }}</x-ui.alert>
        @elseif (! $holdsLock)
            <div class="flex flex-col gap-3 rounded-xl border border-primary/20 bg-primary/5 px-4 py-3 text-sm text-primary-hover sm:flex-row sm:items-center sm:justify-between" role="status" data-free>
                <p class="flex items-center gap-3"><x-ui.icon-chip icon="info" size="sm" />{{ __('Nobody is reviewing this request now.') }}</p>
                <x-ui.button variant="secondary" size="sm" class="self-start sm:self-auto" wire:click="startReview" loading="startReview" data-start-review>{{ __('Start reviewing') }}</x-ui.button>
            </div>
        @endif

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

                    <x-ui.detail :label="__('Evidence')">
                        @if ($record->hasEvidence())
                            <a href="{{ route('requests.evidence', ['type' => $type, 'id' => $record->id]) }}" class="text-primary underline underline-offset-2 hover:text-primary-hover" data-evidence-link>{{ $record->evidence_name }}</a>
                        @else
                            {{ __('None') }}
                        @endif
                    </x-ui.detail>
                </dl>
            </x-ui.card>

            <div class="space-y-6">
                <x-ui.card :title="__('Reason')" icon="list" tone="gray">
                    <p class="px-5 py-4 text-sm whitespace-pre-line wrap-anywhere text-foreground">{{ $record->reason }}</p>
                </x-ui.card>

                @if ($record->decided_at)
                    @include('livewire.requests.partials.decision', ['record' => $record])
                @endif
            </div>
        </div>

        @if ($record instanceof \App\Models\LeaveRequest)
            @if ($record->allocations->isNotEmpty())
                <x-ui.card :title="__('Leave balance used per period')" icon="wallet" tone="green">
                    @include('livewire.requests.partials.allocations', ['record' => $record])
                </x-ui.card>
            @endif

            <x-ui.card :title="__(':name\'s annual leave balance', ['name' => $requester->name])" icon="wallet" tone="green">
                @include('livewire.requests.partials.balance-slots', ['periods' => $periods, 'decider' => true])
            </x-ui.card>
        @else
            <x-ui.card :title="__('Overtime of :name on these dates', ['name' => $requester->name])" icon="clock" tone="teal">
                <x-ui.table :caption="__('Overtime hours per date')">
                    <x-slot:head>
                        <tr>
                            <th scope="col">{{ __('Date') }}</th>
                            <th scope="col">{{ __('Approved') }}</th>
                            <th scope="col">{{ __('Pending, this request included') }}</th>
                            <th scope="col">{{ __('Total') }}</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($days as $day)
                        <tr wire:key="day-{{ $day['date']->toDateString() }}" data-day="{{ $day['date']->toDateString() }}">
                            <td data-card="title"><x-ui.date :value="$day['date']" /></td>
                            <td data-label="{{ __('Approved') }}">{{ __(':count hours', ['count' => $day['approved']]) }}</td>
                            <td data-label="{{ __('Pending, this request included') }}">{{ __(':count hours', ['count' => $day['pending']]) }}</td>
                            <td class="font-medium text-foreground" data-day-total data-label="{{ __('Total') }}">{{ __(':count hours', ['count' => $day['approved'] + $day['pending']]) }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif

        {{-- Both dialogs are always rendered; only their triggers depend on the state (CLAUDE.md lessons). --}}
        <x-ui.modal name="decision-approve" icon="check-circle" :title="__('Approve this request?')">
            <form id="decision-approve-form" class="space-y-4" wire:submit="approve">
                <p class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 font-medium text-amber-900" data-final-warning><x-ui.icon name="alert-triangle" class="mt-0.5 size-4 shrink-0" /><span>{{ __('This decision is final. It cannot be undone or changed later.') }}</span></p>
                @if ($record instanceof \App\Models\LeaveRequest && $record->deducts_balance)
                    <p>{{ __(':count working days will be deducted from the leave balance of :name.', ['count' => $record->working_days, 'name' => $requester->name]) }}</p>
                @endif
                <x-ui.textarea :label="__('Note (optional)')" id="decision-note" maxlength="1000" wire:model="note" :hint="__('Up to 1000 characters. The requester sees it.')" />
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Not now') }}</x-ui.button>
                <x-ui.button type="submit" form="decision-approve-form" loading="approve" data-confirm-approve>{{ __('Approve') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        <x-ui.modal name="decision-reject" icon="x-circle" tone="danger" :title="__('Reject this request?')">
            <form id="decision-reject-form" class="space-y-4" wire:submit="reject">
                <p class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 font-medium text-amber-900" data-final-warning><x-ui.icon name="alert-triangle" class="mt-0.5 size-4 shrink-0" /><span>{{ __('This decision is final. It cannot be undone or changed later.') }}</span></p>
                <x-ui.textarea :label="__('Reason for rejection')" id="decision-reason" maxlength="1000" wire:model="reason" :hint="__('5 to 1000 characters. The requester sees it.')" />
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Not now') }}</x-ui.button>
                <x-ui.button type="submit" variant="danger" form="decision-reject-form" loading="reject" data-confirm-reject>{{ __('Reject') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</x-ui.page>
</div>
