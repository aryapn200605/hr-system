<x-ui.page :title="__('Recap')" :subtitle="$seesOthers ? __('Leave balance and requests of the employees you may see.') : __('Your leave balance and requests.')" icon="chart">
    <x-slot:actions>
        <x-ui.button variant="secondary" size="sm" :href="$exportUrl" data-export>
            <x-ui.icon name="download" class="size-4" />
            {{ __('Export CSV') }}
        </x-ui.button>
    </x-slot:actions>

    <x-ui.card>
        <div class="border-b border-border px-4 pt-4 sm:px-5 sm:pt-5">
            <x-ui.segmented :label="__('Recap')" :options="['balance' => __('Leave balance'), 'departments' => __('By department and month')]" :value="$shownTab" property="tab" data-attribute="tab" class="[&>span]:sr-only [&>div]:mt-0" />
        </div>

        <x-ui.toolbar>
                {{-- Several values in one filter: any of them; several filters: all of them. --}}
                @if ($shownTab === 'balance' && $seesOthers)
                    <x-ui.select :label="__('Employee')" id="filter-employee" multiple remote="searchEmployees" :labels="$employeeLabels" :placeholder="__('All employees')" wire:model.live="employee" />
                @endif

                @if ($seesOthers)
                    <x-ui.select :label="__('Department')" id="filter-department" multiple :placeholder="__('All departments')" wire:model.live="department"
                        :options="$departments->mapWithKeys(fn ($option) => [$option->id => $option->name])->put(\App\Services\Reports\ReportFilters::NO_DEPARTMENT, __('No department'))->all()" />
                @endif

                @if ($shownTab === 'balance')
                    <x-ui.select :label="__('Periods')" id="filter-periods" wire:model.live="periods"
                        :options="['' => __('Current and next period'), 'all' => __('All periods')]" />
                @else
                    {{-- The empty value is the default: this year, and Approved requests only. --}}
                    <x-ui.select :label="__('Year')" id="filter-year" wire:model.live="year"
                        :options="collect($years)->mapWithKeys(fn ($option) => [$option === $thisYear ? '' : (string) $option => (string) $option])->all()" />

                    <x-ui.select :label="__('Status')" id="filter-status" wire:model.live="status"
                        :options="collect(['' => \App\Enums\RequestStatus::Approved->label()])
                            ->merge(collect($statuses)->reject(fn ($option) => $option === \App\Enums\RequestStatus::Approved)->mapWithKeys(fn ($option) => [$option->value => $option->label()]))
                            ->put(\App\Services\Reports\ReportFilters::ALL_STATUSES, __('All statuses'))->all()" />
                @endif
        </x-ui.toolbar>

        <x-ui.table-skeleton target="tab,employee,department,periods,year,status,gotoPage,nextPage,previousPage" :columns="6" :rows="5" />
        <div wire:loading.delay.remove wire:target="tab,employee,department,periods,year,status,gotoPage,nextPage,previousPage">
            @if ($shownTab === 'balance')
                <p class="flex items-start gap-2 border-b border-border bg-muted/30 px-5 py-2.5 text-sm text-muted-foreground">
                    <x-ui.icon name="info" class="mt-0.5 size-4 shrink-0" />
                    {{ __('Remaining is the entitlement minus used days minus approved leave that is deducted when its period opens. Days in pending requests are not subtracted.') }}
                </p>

                @if ($users->isEmpty())
                    <x-ui.empty-state :title="__('No employees found')" :description="__('Try other filters.')" icon="users" />
                @else
                    {{-- One heading row per employee and narrow number columns with wrapping headers, so the table fits beside the menu at 1024px. --}}
                    <x-ui.table :caption="__('Leave balance per employee and period')">
                        <x-slot:head>
                            <tr>
                                <th scope="col">{{ __('Period') }}</th>
                                <th scope="col" class="px-3! align-bottom whitespace-normal!">{{ __('Entitlement') }}</th>
                                <th scope="col" class="px-3! align-bottom whitespace-normal!">{{ __('Used') }}</th>
                                <th scope="col" class="px-3! align-bottom whitespace-normal!">{{ __('Approved, not deducted yet') }}</th>
                                <th scope="col" class="px-3! align-bottom whitespace-normal!">{{ __('In pending requests') }}</th>
                                <th scope="col" class="px-3! align-bottom whitespace-normal!">{{ __('Remaining') }}</th>
                            </tr>
                        </x-slot:head>

                        @foreach ($users as $member)
                            <tr wire:key="balance-{{ $member->id }}" class="bg-slate-50" data-balance-employee="{{ $member->id }}">
                                <th scope="colgroup" colspan="6" class="text-left font-medium text-foreground">
                                    <span class="flex items-center gap-3">
                                        <x-ui.avatar :name="$member->name" class="bg-primary/10 text-primary-hover" />
                                        <span>
                                            {{ $member->name }}
                                            <span class="font-normal text-muted-foreground">· {{ $member->department?->name ?? __('No department') }}</span>
                                        </span>
                                    </span>
                                </th>
                            </tr>
                            @forelse ($lines[$member->id] ?? [] as $line)
                                <tr wire:key="balance-{{ $member->id }}-{{ $loop->index }}" data-balance-row="{{ $member->id }}">
                                    <td>
                                        <x-ui.date :value="$line->startsOn" /> – <x-ui.date :value="$line->endsOn" />
                                        @if ($line->projected)
                                            <span class="mt-1 block"><x-ui.badge>{{ __('Projected') }}</x-ui.badge></span>
                                        @endif
                                    </td>
                                    <td class="px-3!">{{ $line->entitlement }}</td>
                                    <td class="px-3!">{{ $line->used }}</td>
                                    <td class="px-3!">{{ $line->approvedNotDeducted }}</td>
                                    <td class="px-3!">{{ $line->pending }}</td>
                                    <td class="px-3! font-semibold text-green-800" data-remaining>{{ $line->remaining() }}</td>
                                </tr>
                            @empty
                                <tr wire:key="balance-{{ $member->id }}-none" data-balance-row="{{ $member->id }}">
                                    <td colspan="6" class="text-muted-foreground">{{ __('No leave period yet.') }}</td>
                                </tr>
                            @endforelse
                        @endforeach
                    </x-ui.table>
                @endif
            @else
                @if ($rows === [])
                    <x-ui.empty-state :title="__('No requests found')" :description="__('Try other filters.')" icon="chart" />
                @else
                    <x-ui.table :caption="__('Requests per department and month')">
                        <x-slot:head>
                            <tr>
                                <th scope="col" class="align-bottom">{{ __('Department') }}</th>
                                <th scope="col" class="px-3! align-bottom">{{ __('Month') }}</th>
                                @foreach ($categories as $category)
                                    <th scope="col" class="px-3! align-bottom whitespace-normal!">{{ $category->label() }}</th>
                                @endforeach
                                <th scope="col" class="px-3! align-bottom whitespace-normal!">{{ __('Overtime requests') }}</th>
                                <th scope="col" class="px-3! align-bottom whitespace-normal!">{{ __('Overtime hours') }}</th>
                            </tr>
                        </x-slot:head>

                        @foreach ($rows as $row)
                            <tr wire:key="department-{{ $row['department_id'] ?? 'none' }}-{{ $row['month'] }}" data-department-row="{{ $row['department_id'] ?? 'none' }}-{{ $row['month'] }}">
                                <th scope="row" class="text-left font-medium whitespace-normal! text-foreground">{{ $row['department'] ?? __('No department') }}</th>
                                <td class="px-3!">{{ \Carbon\CarbonImmutable::create($shownYear, $row['month'], 1)->translatedFormat('M Y') }}</td>
                                @foreach ($categories as $category)
                                    <td class="px-3!" data-category="{{ $category->code }}">{{ $row['leave'][$category->id] ?? 0 }}</td>
                                @endforeach
                                <td class="px-3!" data-overtime-requests>{{ $row['overtime_requests'] }}</td>
                                <td class="px-3!" data-overtime-hours>{{ $row['overtime_hours'] }}</td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            @endif
        </div>

        @if ($shownTab === 'balance')
            <x-ui.pagination :paginator="$users" />
        @endif
    </x-ui.card>
</x-ui.page>
