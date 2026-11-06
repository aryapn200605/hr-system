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
            {{-- Several values in one filter: any of them; several filters: all of them. --}}
            @if ($seesOthers)
                <x-ui.select :label="__('Employee')" id="filter-employee" multiple remote="searchEmployees" :labels="$employeeLabels" :placeholder="__('All employees')" wire:model.live="employee" />

                <x-ui.select :label="__('Department')" id="filter-department" multiple :placeholder="__('All departments')" wire:model.live="department"
                    :options="$departments->mapWithKeys(fn ($option) => [$option->id => $option->name])->put(\App\Services\Reports\ReportFilters::NO_DEPARTMENT, __('No department'))->all()" />
            @endif

            <x-ui.select :label="__('Request type')" id="filter-type" multiple :placeholder="__('All types')" wire:model.live="type"
                :options="['leave' => __('Leave'), 'overtime' => __('Overtime')]" />

            <x-ui.select :label="__('Category')" id="filter-category" multiple :placeholder="__('All categories')" wire:model.live="category"
                :options="$categories->mapWithKeys(fn ($option) => [$option->code => $option->label()])->all()" />

            <x-ui.select :label="__('Status')" id="filter-status" multiple :placeholder="__('All statuses')" wire:model.live="status"
                :options="collect($statuses)->mapWithKeys(fn ($option) => [$option->value => $option->label()])->all()" />

            <x-ui.date-picker :label="__('Dates')" id="filter-dates" range wire-start="from" wire-end="to" live :placeholder="__('Any date')" />

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
