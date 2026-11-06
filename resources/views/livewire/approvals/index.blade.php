<x-ui.page :title="__('Approvals')" :subtitle="__('Decide the leave and overtime requests that wait for you, oldest first.')">

    <x-ui.card>
        <div class="flex flex-col gap-4 border-b border-slate-200 p-5 sm:flex-row sm:items-end sm:justify-between">
            <div class="inline-flex self-start rounded-lg border border-slate-300 p-0.5" role="group" aria-label="{{ __('List') }}">
                @foreach (['waiting' => __('Waiting for me'), 'decided' => __('Decided by me')] as $value => $label)
                    <button type="button" wire:click="$set('tab', '{{ $value }}')" aria-pressed="{{ $shownTab === $value ? 'true' : 'false' }}" data-tab="{{ $value }}" @class([
                        'cursor-pointer rounded-md px-3 py-1.5 text-sm font-medium',
                        'bg-blue-700 text-white' => $shownTab === $value,
                        'text-slate-700 hover:bg-slate-100' => $shownTab !== $value,
                    ])>{{ $label }}</button>
                @endforeach
            </div>

            <div class="sm:w-56">
                <x-ui.select :label="__('Request type')" id="filter-type" multiple :placeholder="__('All types')" wire:model.live="type"
                    :options="['leave' => __('Leave'), 'overtime' => __('Overtime')]" />
            </div>
        </div>

        <div wire:loading.class="opacity-60" wire:target="tab,type,nextPage,previousPage">
            @if ($rows->isEmpty())
                @if ($shownTab === 'waiting')
                    <x-ui.empty-state :title="__('Nothing waits for you')" :description="__('Requests you may decide appear here.')" icon="inbox" />
                @else
                    <x-ui.empty-state :title="__('No decisions yet')" :description="__('Requests you approve or reject appear here.')" icon="inbox" />
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
                            <td>
                                <a href="{{ route('approvals.show', ['type' => $row->routeType(), 'id' => $row->id]) }}" class="font-medium text-blue-700 underline underline-offset-2 hover:text-blue-900" data-open>
                                    {{ $row->user->name }}<span class="sr-only">: {{ $shownTab === 'waiting' ? __('Review') : __('View') }}</span>
                                </a>
                                <span class="block text-slate-600">{{ $isLeave ? $row->category->label() : __('Overtime') }}</span>
                            </td>
                            <td>
                                @if ($isLeave)
                                    <x-ui.date :value="$row->starts_on" /> – <x-ui.date :value="$row->ends_on" />
                                @else
                                    {{ $row->start_at->translatedFormat('j M Y, H:i') }}–{{ $row->end_at->translatedFormat('H:i T') }}
                                @endif
                                <span class="block text-slate-600">{{ $isLeave ? __(':count working days', ['count' => $row->working_days]) : __(':count hours', ['count' => $row->hours]) }}</span>
                            </td>
                            @if ($shownTab === 'waiting')
                                <td><x-ui.datetime :value="$row->created_at" /></td>
                            @else
                                <td>
                                    <x-ui.badge :tone="$row->status->tone()">{{ $row->status->label() }}</x-ui.badge>
                                    <span class="block text-slate-600"><x-ui.datetime :value="$row->decided_at" /></span>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($rows->hasPages())
            <nav aria-label="{{ __('Pagination') }}" class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3.5">
                <x-ui.button variant="secondary" size="sm" wire:click="previousPage" loading="previousPage" :disabled="$rows->onFirstPage()">{{ __('Previous') }}</x-ui.button>
                <x-ui.button variant="secondary" size="sm" wire:click="nextPage" loading="nextPage" :disabled="! $rows->hasMorePages()">{{ __('Next') }}</x-ui.button>
            </nav>
        @endif
    </x-ui.card>
</x-ui.page>
