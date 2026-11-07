<x-ui.page :title="__('My requests')" :subtitle="__('Submit leave and overtime requests and follow their status.')" icon="clipboard">
    {{-- The buttons stay in the page and are only disabled, so the dialogs are always rendered. --}}
    <x-slot:actions>
        <x-ui.button size="sm" wire:click="openLeave" loading="openLeave" :disabled="$problem !== null" data-new-leave><x-ui.icon name="calendar" class="size-4" wire:loading.remove wire:target="openLeave" />{{ __('New leave request') }}</x-ui.button>
        <x-ui.button variant="secondary" size="sm" wire:click="openOvertime" loading="openOvertime" :disabled="$problem !== null" data-new-overtime><x-ui.icon name="clock" class="size-4" wire:loading.remove wire:target="openOvertime" />{{ __('New overtime request') }}</x-ui.button>
    </x-slot:actions>

    @if ($problem !== null)
        <x-ui.alert tone="warning" data-no-approval-path>{{ $problem }}</x-ui.alert>
    @endif

    <x-ui.card :title="__('Annual leave balance')" icon="wallet" tone="green">
        @include('livewire.requests.partials.balance-slots', ['periods' => $periods])
    </x-ui.card>

    <x-ui.card>
        <x-ui.toolbar>
            {{-- Two columns of the toolbar grid, and its own width in the flex row: the options never overflow. --}}
            <div class="flex items-end sm:col-span-2 xl:w-auto!">
                <x-ui.segmented :label="__('Request type')" :options="['leave' => __('Leave'), 'overtime' => __('Overtime')]" :value="$shownType" property="type" />
            </div>

            <x-ui.select :label="__('Status')" id="filter-status" wire:model.live="statusFilter"
                :options="collect(['' => __('All statuses')])->merge(collect($statuses)->mapWithKeys(fn ($option) => [$option->value => $option->label()]))->all()" />
        </x-ui.toolbar>

        <x-ui.table-skeleton target="type,statusFilter,gotoPage,nextPage,previousPage" :columns="5" :rows="4" />
        <div wire:loading.delay.remove wire:target="type,statusFilter,gotoPage,nextPage,previousPage">
            @if ($requests->isEmpty())
                <x-ui.empty-state :title="__('No requests found')" :description="__('Requests you submit appear here.')" :icon="$shownType === 'leave' ? 'calendar' : 'clock'">
                    @if ($problem === null)
                        @if ($shownType === 'leave')
                            <x-ui.button size="sm" wire:click="openLeave" loading="openLeave"><x-ui.icon name="plus" class="size-4" wire:loading.remove wire:target="openLeave" />{{ __('New leave request') }}</x-ui.button>
                        @else
                            <x-ui.button size="sm" wire:click="openOvertime" loading="openOvertime"><x-ui.icon name="plus" class="size-4" wire:loading.remove wire:target="openOvertime" />{{ __('New overtime request') }}</x-ui.button>
                        @endif
                    @endif
                </x-ui.empty-state>
            @elseif ($shownType === 'leave')
                <x-ui.table :caption="__('Leave requests')">
                    <x-slot:head>
                        <tr>
                            <th scope="col">{{ __('Category') }}</th>
                            <th scope="col">{{ __('Dates') }}</th>
                            <th scope="col">{{ __('Working days') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col">{{ __('Submitted') }}</th>
                            <th scope="col" class="w-px"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </x-slot:head>

                    @foreach ($requests as $row)
                        <tr wire:key="leave-{{ $row->id }}">
                            <td>
                                <span class="flex items-center gap-3">
                                    <x-ui.icon-chip icon="calendar" size="sm" />
                                    <span class="font-medium text-foreground">{{ $row->category->label() }}</span>
                                </span>
                            </td>
                            <td><x-ui.date :value="$row->starts_on" /> – <x-ui.date :value="$row->ends_on" /></td>
                            <td class="tabular-nums">{{ $row->working_days }}</td>
                            <td><x-ui.badge :tone="$row->status->tone()" dot>{{ $row->status->label() }}</x-ui.badge></td>
                            <td><x-ui.datetime :value="$row->created_at" /></td>
                            <td class="text-right"><x-ui.button variant="secondary" size="sm" :href="route('requests.show', ['type' => 'leave', 'id' => $row->id])"><x-ui.icon name="eye" class="size-4" />{{ __('View') }}</x-ui.button></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @else
                <x-ui.table :caption="__('Overtime requests')">
                    <x-slot:head>
                        <tr>
                            <th scope="col">{{ __('Start') }}</th>
                            <th scope="col">{{ __('End') }}</th>
                            <th scope="col">{{ __('Hours') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col">{{ __('Submitted') }}</th>
                            <th scope="col" class="w-px"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </x-slot:head>

                    @foreach ($requests as $row)
                        <tr wire:key="overtime-{{ $row->id }}">
                            <td>
                                <span class="flex items-center gap-3">
                                    <x-ui.icon-chip icon="clock" size="sm" tone="teal" />
                                    <span class="font-medium text-foreground"><x-ui.datetime :value="$row->start_at" /></span>
                                </span>
                            </td>
                            <td><x-ui.datetime :value="$row->end_at" /></td>
                            <td class="tabular-nums">{{ $row->hours }}</td>
                            <td><x-ui.badge :tone="$row->status->tone()" dot>{{ $row->status->label() }}</x-ui.badge></td>
                            <td><x-ui.datetime :value="$row->created_at" /></td>
                            <td class="text-right"><x-ui.button variant="secondary" size="sm" :href="route('requests.show', ['type' => 'overtime', 'id' => $row->id])"><x-ui.icon name="eye" class="size-4" />{{ __('View') }}</x-ui.button></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        <x-ui.pagination :paginator="$requests" />
    </x-ui.card>

    <x-ui.modal name="leave-form" icon="calendar" :title="__('New leave request')" class="sm:max-w-xl">
        <form id="leave-form-fields" class="space-y-4" wire:submit="submitLeave">
            @error('request')
                <x-ui.alert tone="danger" data-request-error>{{ $message }}</x-ui.alert>
            @enderror

            <x-ui.select :label="__('Category')" id="leave-category" wire:model="category"
                :options="$categories->map(fn ($option) => ['value' => $option->code, 'label' => $option->label(), 'hint' => $option->deducts_balance ? __('uses leave balance') : null])->all()" />

            {{-- Today and the earliest date come from the server (WIB). Weekends and holidays are greyed out. --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.date-picker :label="__('Start date')" id="leave-starts-on" :min="today()->toDateString()" disable-weekends :disabled-dates="$holidays" wire:model="starts_on" />
                <x-ui.date-picker :label="__('End date')" id="leave-ends-on" :min="today()->toDateString()" disable-weekends :disabled-dates="$holidays" wire:model="ends_on" />
            </div>
            <p class="flex items-start gap-2 text-sm text-muted-foreground"><x-ui.icon name="info" class="mt-0.5 size-4 shrink-0" /><span>{{ __('Only working days count: Monday to Friday, without holidays. The count is fixed when you submit.') }}</span></p>

            <x-ui.textarea :label="__('Reason')" id="leave-reason" maxlength="1000" wire:model="reason" :hint="__('10 to 1000 characters.')" />

            @include('livewire.requests.partials.evidence-input', ['field' => 'evidence', 'inputId' => 'leave-evidence'])
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="leave-form-fields" loading="submitLeave,evidence">{{ __('Submit request') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="overtime-form" icon="clock" :title="__('New overtime request')" class="sm:max-w-xl">
        <form id="overtime-form-fields" class="space-y-4" wire:submit="submitOvertime">
            @error('request')
                <x-ui.alert tone="danger" data-request-error>{{ $message }}</x-ui.alert>
            @enderror

            <div class="space-y-4">
                <x-ui.date-hour :legend="__('Start')" id="overtime-start" date="start_date" hour="start_hour" :date-label="__('Start date')" :hour-label="__('Start time')" :min="today()->toDateString()" />
                <x-ui.date-hour :legend="__('End')" id="overtime-end" date="end_date" hour="end_hour" :date-label="__('End date')" :hour-label="__('End time')" :min="today()->toDateString()" />
            </div>
            <p class="flex items-start gap-2 text-sm text-muted-foreground"><x-ui.icon name="info" class="mt-0.5 size-4 shrink-0" /><span>{{ __('Whole hours in WIB, at most :count hours. The end may be on the next day.', ['count' => $maxHours]) }}</span></p>

            <x-ui.textarea :label="__('Reason')" id="overtime-reason" maxlength="1000" wire:model="overtime_reason" :hint="__('10 to 1000 characters.')" />

            @include('livewire.requests.partials.evidence-input', ['field' => 'overtime_evidence', 'inputId' => 'overtime-evidence'])
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="overtime-form-fields" loading="submitOvertime,overtime_evidence">{{ __('Submit request') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
