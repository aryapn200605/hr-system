<x-ui.page :title="__('Request history')" :subtitle="$seesOthers ? __('Leave and overtime requests you may see, newest first.') : __('Your leave and overtime requests, newest first.')">
    <x-slot:actions>
        {{-- A plain link: the export is a streamed download with the filters shown here. --}}
        <x-ui.button variant="secondary" size="sm" :href="$exportUrl" data-export>
            <x-ui.icon name="download" class="size-4" />
            {{ __('Export CSV') }}
        </x-ui.button>
    </x-slot:actions>

    <x-ui.card>
        <div class="grid grid-cols-1 gap-4 border-b border-slate-200 p-5 sm:grid-cols-2 lg:grid-cols-4">
            @if ($seesOthers)
                <x-ui.select :label="__('Employee')" id="filter-employee" wire:model.live="employee">
                    <option value="">{{ __('All employees') }}</option>
                    @foreach ($employees as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select :label="__('Department')" id="filter-department" wire:model.live="department">
                    <option value="">{{ __('All departments') }}</option>
                    @foreach ($departments as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                    <option value="{{ \App\Services\Reports\ReportFilters::NO_DEPARTMENT }}">{{ __('No department') }}</option>
                </x-ui.select>
            @endif

            <x-ui.select :label="__('Request type')" id="filter-type" wire:model.live="type">
                <option value="">{{ __('All types') }}</option>
                <option value="leave">{{ __('Leave') }}</option>
                <option value="overtime">{{ __('Overtime') }}</option>
            </x-ui.select>

            <x-ui.select :label="__('Category')" id="filter-category" wire:model.live="category">
                <option value="">{{ __('All categories') }}</option>
                @foreach ($categories as $option)
                    <option value="{{ $option->code }}">{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Status')" id="filter-status" wire:model.live="status">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.input type="date" :label="__('From')" id="filter-from" wire:model.live.blur="from" />
            <x-ui.input type="date" :label="__('To')" id="filter-to" wire:model.live.blur="to" />

            @if ($filtered)
                <div class="flex items-end">
                    <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" loading="clearFilters" data-clear-filters>{{ __('Clear filters') }}</x-ui.button>
                </div>
            @endif
        </div>

        <p class="border-b border-slate-200 px-5 py-2.5 text-sm text-slate-600" data-history-total>
            {{ __('Requests found: :count', ['count' => $rows->total()]) }}
            @if ($filtered)
                · {{ __('A request is listed when its dates overlap the range.') }}
            @endif
        </p>

        <div wire:loading.class="opacity-60" wire:target="employee,department,type,category,status,from,to,clearFilters,nextPage,previousPage">
            @if ($rows->isEmpty())
                <x-ui.empty-state :title="__('No requests found')" :description="$filtered ? __('Try other filters.') : __('Requests appear here once they are submitted.')" icon="clock" />
            @else
                <x-ui.table :caption="__('Request history')">
                    <x-slot:head>
                        <tr>
                            <th scope="col">{{ $seesOthers ? __('Requester') : __('Request') }}</th>
                            <th scope="col">{{ __('When') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                        </tr>
                    </x-slot:head>

                    {{-- Three columns with extra lines each, so the table fits beside the menu at 1024px. --}}
                    @foreach ($rows as $row)
                        @php($key = $row->type.'-'.$row->id)
                        @php($title = $seesOthers ? $row->requester : $row->kindLabel())
                        <tr wire:key="{{ $key }}" data-row="{{ $key }}">
                            <td>
                                @isset($links[$key])
                                    <a href="{{ $links[$key] }}" class="font-medium text-blue-700 underline underline-offset-2 hover:text-blue-900" data-open>
                                        {{ $title }}<span class="sr-only">: {{ __('View') }}</span>
                                    </a>
                                @else
                                    <span class="font-medium text-slate-900">{{ $title }}</span>
                                @endisset
                                <span class="block text-slate-600">
                                    @if ($seesOthers)
                                        {{ $row->kindLabel() }} · {{ $row->department ?? __('No department') }}
                                    @else
                                        {{ $row->isLeave() ? __('Leave') : __('Overtime') }}
                                    @endif
                                </span>
                            </td>
                            <td>
                                @if ($row->isLeave())
                                    <x-ui.date :value="$row->start" /> – <x-ui.date :value="$row->end" />
                                @else
                                    {{ $row->start->translatedFormat('j M Y, H:i') }}–{{ $row->end->translatedFormat('H:i T') }}
                                @endif
                                <span class="block text-slate-600">{{ $row->isLeave() ? __(':count working days', ['count' => $row->amount]) : __(':count hours', ['count' => $row->amount]) }}</span>
                            </td>
                            <td>
                                <x-ui.badge :tone="$row->status->tone()">{{ $row->status->label() }}</x-ui.badge>
                                @if ($row->decider !== null)
                                    <span class="block text-slate-600">{{ __('by :name', ['name' => $row->decider]) }}</span>
                                @endif
                                <span class="block text-slate-600">{{ __('Submitted') }} <x-ui.datetime :value="$row->submittedAt" /></span>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($rows->hasPages())
            <nav aria-label="{{ __('Pagination') }}" class="flex items-center justify-between gap-2 border-t border-slate-200 px-5 py-3.5">
                <p class="text-sm text-slate-600">{{ __('Page :page of :pages', ['page' => $rows->currentPage(), 'pages' => $rows->lastPage()]) }}</p>
                <div class="flex gap-2">
                    <x-ui.button variant="secondary" size="sm" wire:click="previousPage" loading="previousPage" :disabled="$rows->onFirstPage()">{{ __('Previous') }}</x-ui.button>
                    <x-ui.button variant="secondary" size="sm" wire:click="nextPage" loading="nextPage" :disabled="! $rows->hasMorePages()">{{ __('Next') }}</x-ui.button>
                </div>
            </nav>
        @endif
    </x-ui.card>
</x-ui.page>
