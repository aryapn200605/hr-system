<x-ui.page :title="__('Approvals')" :subtitle="__('Decide the leave and overtime requests that wait for you, oldest first.')" icon="inbox">

    <x-ui.card>
        <x-ui.toolbar>
            {{-- Two columns of the toolbar grid, and its own width in the flex row: the options never overflow. --}}
            <div class="flex items-end sm:col-span-2 xl:w-auto!">
                <x-ui.segmented :label="__('List')" :options="['waiting' => __('Waiting for me'), 'decided' => __('Decided by me')]" :value="$shownTab" property="tab" data-attribute="tab" />
            </div>

            <x-ui.select :label="__('Request type')" id="filter-type" multiple :placeholder="__('All types')" wire:model.live="type"
                :options="['leave' => __('Leave'), 'overtime' => __('Overtime')]" />
        </x-ui.toolbar>

        <x-ui.table-skeleton target="tab,type,gotoPage,nextPage,previousPage" :columns="3" :rows="4" />
        <div wire:loading.delay.remove wire:target="tab,type,gotoPage,nextPage,previousPage">
            @if ($rows->isEmpty() && $type !== [])
                {{-- Phase 15e: the type filter hides every row; offer to show both types again. --}}
                <x-ui.empty-state :title="__('No requests of this type')" :description="__('Show both types to see every request in this list.')" icon="search" data-empty="filtered">
                    <x-ui.button variant="secondary" size="sm" wire:click="$set('type', [])" data-clear-filters>{{ __('Clear filters') }}</x-ui.button>
                </x-ui.empty-state>
            @elseif ($rows->isEmpty())
                @if ($shownTab === 'waiting')
                    <x-ui.empty-state :title="__('Nothing waits for you')" :description="__('Requests you may decide appear here.')" icon="inbox" data-empty="none">
                        <x-ui.button variant="secondary" size="sm" wire:click="$set('tab', 'decided')" data-show-decided>{{ __('Decided by me') }}</x-ui.button>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state :title="__('No decisions yet')" :description="__('Requests you approve or reject appear here.')" icon="check-circle" data-empty="none" />
                @endif
            @else
                <x-ui.table :caption="$shownTab === 'waiting' ? __('Requests waiting for me') : __('Requests decided by me')">
                    <x-slot:head>
                        <tr>
                            <th scope="col">{{ __('Requester') }}</th>
                            <th scope="col">{{ __('When') }}</th>
                            <th scope="col">{{ $shownTab === 'waiting' ? __('Submitted') : __('Decision') }}</th>
                        </tr>
                    </x-slot:head>

                    {{-- Three columns with a second line each, so the table fits beside the menu at 1024px. --}}
                    @foreach ($rows as $row)
                        @php($isLeave = $row instanceof \App\Models\LeaveRequest)
                        <tr wire:key="{{ $row->routeType() }}-{{ $row->id }}" data-row="{{ $row->routeType() }}-{{ $row->id }}">
                            <td data-card="title">
                                <span class="flex items-center gap-3">
                                    <x-ui.avatar :name="$row->user->name" class="bg-primary/10 text-primary-hover" />
                                    <span class="min-w-0">
                                        <a href="{{ route('approvals.show', ['type' => $row->routeType(), 'id' => $row->id]) }}" class="font-medium text-primary underline underline-offset-2 hover:text-primary-hover" data-open>
                                            {{ $row->user->name }}<span class="sr-only">: {{ $shownTab === 'waiting' ? __('Review') : __('View') }}</span>
                                        </a>
                                        <span class="flex items-center gap-1.5 text-muted-foreground">
                                            <x-ui.icon :name="$isLeave ? 'calendar' : 'clock'" class="size-3.5 shrink-0" />
                                            {{ $isLeave ? $row->category->label() : __('Overtime') }}
                                        </span>
                                    </span>
                                </span>
                            </td>
                            <td data-label="{{ __('When') }}">
                                @if ($isLeave)
                                    <x-ui.date :value="$row->starts_on" /> – <x-ui.date :value="$row->ends_on" />
                                @else
                                    {{ $row->start_at->translatedFormat('j M Y, H:i') }}–{{ $row->end_at->translatedFormat('H:i T') }}
                                @endif
                                <span class="block text-muted-foreground">{{ $isLeave ? __(':count working days', ['count' => $row->working_days]) : __(':count hours', ['count' => $row->hours]) }}</span>
                            </td>
                            @if ($shownTab === 'waiting')
                                <td data-label="{{ __('Submitted') }}"><x-ui.datetime :value="$row->created_at" /></td>
                            @else
                                <td data-label="{{ __('Decision') }}">
                                    <x-ui.badge :tone="$row->status->tone()" dot>{{ $row->status->label() }}</x-ui.badge>
                                    <span class="block text-muted-foreground"><x-ui.datetime :value="$row->decided_at" /></span>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        <x-ui.pagination :paginator="$rows" />
    </x-ui.card>
</x-ui.page>
