<x-ui.page :title="__('Recap')" :subtitle="$seesOthers ? __('Leave balance and requests of the employees you may see.') : __('Your leave balance and requests.')">
    <x-slot:actions>
        <x-ui.button variant="secondary" size="sm" :href="$exportUrl" data-export>
            <x-ui.icon name="download" class="size-4" />
            {{ __('Export CSV') }}
        </x-ui.button>
    </x-slot:actions>

    <x-ui.card>
        <div class="flex flex-col gap-4 border-b border-slate-200 p-5">
            <div class="inline-flex self-start rounded-lg border border-slate-300 p-0.5" role="group" aria-label="{{ __('Recap') }}">
                @foreach (['balance' => __('Leave balance'), 'departments' => __('By department and month')] as $value => $label)
                    <button type="button" wire:click="$set('tab', '{{ $value }}')" aria-pressed="{{ $shownTab === $value ? 'true' : 'false' }}" data-tab="{{ $value }}" @class([
                        'cursor-pointer rounded-md px-3 py-1.5 text-sm font-medium',
                        'bg-blue-700 text-white' => $shownTab === $value,
                        'text-slate-700 hover:bg-slate-100' => $shownTab !== $value,
                    ])>{{ $label }}</button>
                @endforeach
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
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
            </div>
        </div>

        <div wire:loading.class="opacity-60" wire:target="tab,employee,department,periods,year,status,nextPage,previousPage">
            @if ($shownTab === 'balance')
                <p class="border-b border-slate-200 px-5 py-2.5 text-sm text-slate-600">
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
                                <th scope="colgroup" colspan="6" class="text-left font-medium text-slate-900">
                                    {{ $member->name }}
                                    <span class="font-normal text-slate-600">· {{ $member->department?->name ?? __('No department') }}</span>
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
                                    <td class="px-3! font-medium text-slate-900" data-remaining>{{ $line->remaining() }}</td>
                                </tr>
                            @empty
                                <tr wire:key="balance-{{ $member->id }}-none" data-balance-row="{{ $member->id }}">
                                    <td colspan="6" class="text-slate-600">{{ __('No leave period yet.') }}</td>
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
                                <th scope="row" class="text-left font-medium whitespace-normal! text-slate-900">{{ $row['department'] ?? __('No department') }}</th>
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

        @if ($shownTab === 'balance' && $users->hasPages())
            <nav aria-label="{{ __('Pagination') }}" class="flex items-center justify-between gap-2 border-t border-slate-200 px-5 py-3.5">
                <p class="text-sm text-slate-600">{{ __('Page :page of :pages', ['page' => $users->currentPage(), 'pages' => $users->lastPage()]) }}</p>
                <div class="flex gap-2">
                    <x-ui.button variant="secondary" size="sm" wire:click="previousPage" loading="previousPage" :disabled="$users->onFirstPage()">{{ __('Previous') }}</x-ui.button>
                    <x-ui.button variant="secondary" size="sm" wire:click="nextPage" loading="nextPage" :disabled="! $users->hasMorePages()">{{ __('Next') }}</x-ui.button>
                </div>
            </nav>
        @endif
    </x-ui.card>
</x-ui.page>
