<x-ui.page :title="__('Request history')" :subtitle="$seesOthers ? __('Leave and overtime requests you may see, newest first.') : __('Your leave and overtime requests, newest first.')" icon="history">
    <x-slot:actions>
        {{-- A plain link: the export is a streamed download with the filters shown here. --}}
        <x-ui.button variant="secondary" size="sm" :href="$exportUrl" data-export>
            <x-ui.icon name="download" class="size-4" />
            {{ __('Export CSV') }}
        </x-ui.button>
    </x-slot:actions>

    <x-ui.card>
        <x-ui.toolbar>
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
                <x-slot:actions>
                    <x-ui.button variant="ghost" size="sm" wire:click="clearFilters" loading="clearFilters" data-clear-filters><x-ui.icon name="close" class="size-4" wire:loading.remove wire:target="clearFilters" />{{ __('Clear filters') }}</x-ui.button>
                </x-slot:actions>
            @endif
        </x-ui.toolbar>

        <p class="flex flex-wrap items-center gap-x-1.5 border-b border-border bg-muted/30 px-5 py-2.5 text-sm text-muted-foreground" data-history-total>
            <x-ui.icon name="list" class="size-4 shrink-0" />
            {{ __('Requests found: :count', ['count' => $rows->total()]) }}
            @if ($filtered)
                · {{ __('A request is listed when its dates overlap the range.') }}
            @endif
        </p>

        <x-ui.table-skeleton target="employee,department,type,category,status,from,to,clearFilters,gotoPage,nextPage,previousPage" :columns="3" :rows="5" />
        <div wire:loading.delay.remove wire:target="employee,department,type,category,status,from,to,clearFilters,gotoPage,nextPage,previousPage">
            @if ($rows->isEmpty())
                <x-ui.empty-state :title="__('No requests found')" :description="$filtered ? __('Try other filters.') : __('Requests appear here once they are submitted.')" icon="history">
                    @if ($filtered)
                        <x-ui.button variant="secondary" size="sm" wire:click="clearFilters" loading="clearFilters">{{ __('Clear filters') }}</x-ui.button>
                    @endif
                </x-ui.empty-state>
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
                                <span class="flex items-center gap-3">
                                @if ($seesOthers)
                                    <x-ui.avatar :name="$row->requester" class="bg-primary/10 text-primary-hover" />
                                @else
                                    <x-ui.icon-chip :icon="$row->isLeave() ? 'calendar' : 'clock'" :tone="$row->isLeave() ? 'primary' : 'teal'" size="sm" />
                                @endif
                                <span class="min-w-0">
                                @isset($links[$key])
                                    <a href="{{ $links[$key] }}" class="font-medium text-primary underline underline-offset-2 hover:text-primary-hover" data-open>
                                        {{ $title }}<span class="sr-only">: {{ __('View') }}</span>
                                    </a>
                                @else
                                    <span class="font-medium text-foreground">{{ $title }}</span>
                                @endisset
                                <span class="block text-muted-foreground">
                                    @if ($seesOthers)
                                        {{ $row->kindLabel() }} · {{ $row->department ?? __('No department') }}
                                    @else
                                        {{ $row->isLeave() ? __('Leave') : __('Overtime') }}
                                    @endif
                                </span>
                                </span>
                                </span>
                            </td>
                            <td>
                                @if ($row->isLeave())
                                    <x-ui.date :value="$row->start" /> – <x-ui.date :value="$row->end" />
                                @else
                                    {{ $row->start->translatedFormat('j M Y, H:i') }}–{{ $row->end->translatedFormat('H:i T') }}
                                @endif
                                <span class="block text-muted-foreground">{{ $row->isLeave() ? __(':count working days', ['count' => $row->amount]) : __(':count hours', ['count' => $row->amount]) }}</span>
                            </td>
                            <td>
                                <x-ui.badge :tone="$row->status->tone()" dot>{{ $row->status->label() }}</x-ui.badge>
                                @if ($row->decider !== null)
                                    <span class="block text-muted-foreground">{{ __('by :name', ['name' => $row->decider]) }}</span>
                                @endif
                                <span class="block text-muted-foreground">{{ __('Submitted') }} <x-ui.datetime :value="$row->submittedAt" /></span>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        <x-ui.pagination :paginator="$rows" />
    </x-ui.card>
</x-ui.page>
