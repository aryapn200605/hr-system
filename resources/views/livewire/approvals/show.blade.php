<div
    class="space-y-6"
    @if ($record !== null && $holdsLock) wire:poll.{{ $heartbeat }}s="heartbeat" @elseif ($record !== null && $viewOnly) wire:poll.30s @endif
    @if ($record !== null)
        {{-- Closing the tab or leaving the page lets go of the lock at once; a Livewire call would not survive the unload. --}}
        x-data
        x-on:pagehide.window="if ($wire.holdsLock) navigator.sendBeacon({{ \Illuminate\Support\Js::from(route('approvals.release', ['type' => $type, 'id' => $requestId])) }}, new URLSearchParams({ _token: {{ \Illuminate\Support\Js::from(csrf_token()) }} }))"
    @endif
>
    <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $title }}</h1>
            @if ($record !== null)
                <p class="mt-2 text-sm text-slate-600">{{ $requester->name }} · {{ __('Submitted') }} <x-ui.datetime :value="$record->created_at" /></p>
            @endif
        </div>

        <div class="flex shrink-0 flex-wrap gap-2 self-start">
            <x-ui.button variant="secondary" size="sm" wire:click="close" loading="close" data-close>{{ __('Back to approvals') }}</x-ui.button>
            @if ($record !== null && $canDecide)
                <x-ui.button size="sm" wire:click="openApprove" loading="openApprove" data-approve>{{ __('Approve') }}</x-ui.button>
                <x-ui.button variant="secondary" size="sm" class="text-red-800!" wire:click="openReject" loading="openReject" data-reject>{{ __('Reject') }}</x-ui.button>
            @endif
        </div>
    </header>

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    @if ($record !== null)
        @if ($viewOnly)
            <div class="flex flex-col gap-3 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2.5 text-sm text-blue-900 sm:flex-row sm:items-center sm:justify-between" role="status" data-view-only>
                <p>{{ $heldMessage }}</p>
            </div>
        @elseif ($outcome !== null)
            <x-ui.alert data-outcome>{{ $outcome }}</x-ui.alert>
        @elseif (! $holdsLock)
            <div class="flex flex-col gap-3 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2.5 text-sm text-blue-900 sm:flex-row sm:items-center sm:justify-between" role="status" data-free>
                <p>{{ __('Nobody is reviewing this request now.') }}</p>
                <x-ui.button variant="secondary" size="sm" class="self-start sm:self-auto" wire:click="startReview" loading="startReview" data-start-review>{{ __('Start reviewing') }}</x-ui.button>
            </div>
        @endif

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

        @if ($record instanceof \App\Models\LeaveRequest)
            @if ($record->allocations->isNotEmpty())
                <x-ui.card :title="__('Leave balance used per period')">
                    @include('livewire.requests.partials.allocations', ['record' => $record])
                </x-ui.card>
            @endif

            <x-ui.card :title="__(':name\'s annual leave balance', ['name' => $requester->name])">
                <div class="grid grid-cols-1 divide-y divide-slate-200 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                    @foreach ($periods as $slot)
                        <dl class="px-5 py-2" data-slot="{{ $loop->index }}">
                            <x-ui.detail :label="$loop->first ? __('Current period') : __('Next period')">
                                <x-ui.date :value="$slot->startsOn" /> – <x-ui.date :value="$slot->endsOn" />
                                @if ($slot->isProjected())
                                    <x-ui.badge class="ml-1">{{ __('Projected') }}</x-ui.badge>
                                @endif
                            </x-ui.detail>
                            <x-ui.detail :label="__('Entitlement')">{{ __(':count days', ['count' => $slot->entitlement]) }}</x-ui.detail>
                            <x-ui.detail :label="__('Used')"><span data-used>{{ __(':count days', ['count' => $slot->used]) }}</span></x-ui.detail>
                            <x-ui.detail :label="__('In pending requests')">{{ __(':count days', ['count' => $slot->pending]) }}</x-ui.detail>
                            <x-ui.detail :label="__('Remaining after used days')"><span data-remaining>{{ __(':count days', ['count' => max(0, $slot->entitlement - $slot->used)]) }}</span></x-ui.detail>
                        </dl>
                    @endforeach
                </div>
            </x-ui.card>
        @else
            <x-ui.card :title="__('Overtime of :name on these dates', ['name' => $requester->name])">
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
                            <td><x-ui.date :value="$day['date']" /></td>
                            <td>{{ __(':count hours', ['count' => $day['approved']]) }}</td>
                            <td>{{ __(':count hours', ['count' => $day['pending']]) }}</td>
                            <td class="font-medium text-slate-900" data-day-total>{{ __(':count hours', ['count' => $day['approved'] + $day['pending']]) }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif

        {{-- Both dialogs are always rendered; only their triggers depend on the state (CLAUDE.md lessons). --}}
        <x-ui.modal name="decision-approve" :title="__('Approve this request?')">
            <form id="decision-approve-form" class="space-y-4" wire:submit="approve">
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

        <x-ui.modal name="decision-reject" :title="__('Reject this request?')">
            <form id="decision-reject-form" class="space-y-4" wire:submit="reject">
                <x-ui.textarea :label="__('Reason for rejection')" id="decision-reason" maxlength="1000" wire:model="reason" :hint="__('5 to 1000 characters. The requester sees it.')" />
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Not now') }}</x-ui.button>
                <x-ui.button type="submit" variant="danger" form="decision-reject-form" loading="reject" data-confirm-reject>{{ __('Reject') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
