<x-ui.page :title="__('My requests')" :subtitle="__('Submit leave and overtime requests and follow their status.')">
    {{-- The buttons stay in the page and are only disabled, so the dialogs are always rendered. --}}
    <x-slot:actions>
        <x-ui.button size="sm" wire:click="openLeave" loading="openLeave" :disabled="$problem !== null" data-new-leave>{{ __('New leave request') }}</x-ui.button>
        <x-ui.button variant="secondary" size="sm" wire:click="openOvertime" loading="openOvertime" :disabled="$problem !== null" data-new-overtime>{{ __('New overtime request') }}</x-ui.button>
    </x-slot:actions>

    @if ($problem !== null)
        <x-ui.alert tone="warning" data-no-approval-path>{{ $problem }}</x-ui.alert>
    @endif

    <x-ui.card :title="__('Annual leave balance')">
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
                    <x-ui.detail :label="__('Used')">{{ __(':count days', ['count' => $slot->used]) }}</x-ui.detail>
                    <x-ui.detail :label="__('In pending requests')">{{ __(':count days', ['count' => $slot->pending]) }}</x-ui.detail>
                    <x-ui.detail :label="__('Available')"><span data-available>{{ __(':count days', ['count' => $slot->available()]) }}</span></x-ui.detail>
                </dl>
            @endforeach
        </div>
    </x-ui.card>

    <x-ui.card>
        <div class="flex flex-col gap-4 border-b border-slate-200 p-5 sm:flex-row sm:items-end sm:justify-between">
            <div class="inline-flex self-start rounded-lg border border-slate-300 p-0.5" role="group" aria-label="{{ __('Request type') }}">
                @foreach (['leave' => __('Leave'), 'overtime' => __('Overtime')] as $value => $label)
                    <button type="button" wire:click="$set('type', '{{ $value }}')" aria-pressed="{{ $shownType === $value ? 'true' : 'false' }}" @class([
                        'cursor-pointer rounded-md px-3 py-1.5 text-sm font-medium',
                        'bg-blue-700 text-white' => $shownType === $value,
                        'text-slate-700 hover:bg-slate-100' => $shownType !== $value,
                    ])>{{ $label }}</button>
                @endforeach
            </div>

            <div class="sm:w-56">
                <x-ui.select :label="__('Status')" id="filter-status" wire:model.live="statusFilter">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach ($statuses as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>
        </div>

        <div wire:loading.class="opacity-60" wire:target="type,statusFilter,nextPage,previousPage">
            @if ($requests->isEmpty())
                <x-ui.empty-state :title="__('No requests found')" :description="__('Requests you submit appear here.')" icon="clipboard" />
            @elseif ($shownType === 'leave')
                <x-ui.table :caption="__('Leave requests')">
                    <x-slot:head>
                        <tr>
                            <th scope="col">{{ __('Category') }}</th>
                            <th scope="col">{{ __('Dates') }}</th>
                            <th scope="col">{{ __('Working days') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col">{{ __('Submitted') }}</th>
                            <th scope="col">{{ __('Actions') }}</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($requests as $row)
                        <tr wire:key="leave-{{ $row->id }}">
                            <td class="font-medium text-slate-900">{{ $row->category->label() }}</td>
                            <td><x-ui.date :value="$row->starts_on" /> – <x-ui.date :value="$row->ends_on" /></td>
                            <td>{{ $row->working_days }}</td>
                            <td><x-ui.badge :tone="$row->status->tone()">{{ $row->status->label() }}</x-ui.badge></td>
                            <td><x-ui.datetime :value="$row->created_at" /></td>
                            <td class="text-right"><x-ui.button variant="secondary" size="sm" :href="route('requests.show', ['type' => 'leave', 'id' => $row->id])">{{ __('View') }}</x-ui.button></td>
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
                            <th scope="col">{{ __('Actions') }}</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($requests as $row)
                        <tr wire:key="overtime-{{ $row->id }}">
                            <td class="font-medium text-slate-900"><x-ui.datetime :value="$row->start_at" /></td>
                            <td><x-ui.datetime :value="$row->end_at" /></td>
                            <td>{{ $row->hours }}</td>
                            <td><x-ui.badge :tone="$row->status->tone()">{{ $row->status->label() }}</x-ui.badge></td>
                            <td><x-ui.datetime :value="$row->created_at" /></td>
                            <td class="text-right"><x-ui.button variant="secondary" size="sm" :href="route('requests.show', ['type' => 'overtime', 'id' => $row->id])">{{ __('View') }}</x-ui.button></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($requests->hasPages())
            <nav aria-label="{{ __('Pagination') }}" class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3.5">
                <x-ui.button variant="secondary" size="sm" wire:click="previousPage" loading="previousPage" :disabled="$requests->onFirstPage()">{{ __('Previous') }}</x-ui.button>
                <x-ui.button variant="secondary" size="sm" wire:click="nextPage" loading="nextPage" :disabled="! $requests->hasMorePages()">{{ __('Next') }}</x-ui.button>
            </nav>
        @endif
    </x-ui.card>

    <x-ui.modal name="leave-form" :title="__('New leave request')">
        <form id="leave-form-fields" class="space-y-4" wire:submit="submitLeave">
            @error('request')
                <x-ui.alert tone="danger" data-request-error>{{ $message }}</x-ui.alert>
            @enderror

            <x-ui.select :label="__('Category')" id="leave-category" wire:model="category">
                @foreach ($categories as $option)
                    <option value="{{ $option->code }}">{{ $option->label() }}{{ $option->deducts_balance ? ' · '.__('uses leave balance') : '' }}</option>
                @endforeach
            </x-ui.select>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input :label="__('Start date')" id="leave-starts-on" type="date" min="{{ today()->toDateString() }}" wire:model="starts_on" />
                <x-ui.input :label="__('End date')" id="leave-ends-on" type="date" min="{{ today()->toDateString() }}" wire:model="ends_on" />
            </div>
            <p class="text-sm text-slate-600">{{ __('Only working days count: Monday to Friday, without holidays. The count is fixed when you submit.') }}</p>

            <x-ui.textarea :label="__('Reason')" id="leave-reason" maxlength="1000" wire:model="reason" :hint="__('10 to 1000 characters.')" />

            @include('livewire.requests.partials.evidence-input', ['field' => 'evidence', 'inputId' => 'leave-evidence'])
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="leave-form-fields" loading="submitLeave,evidence">{{ __('Submit request') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="overtime-form" :title="__('New overtime request')">
        <form id="overtime-form-fields" class="space-y-4" wire:submit="submitOvertime">
            @error('request')
                <x-ui.alert tone="danger" data-request-error>{{ $message }}</x-ui.alert>
            @enderror

            <div class="grid grid-cols-2 gap-4">
                <x-ui.input :label="__('Start date')" id="overtime-start-date" type="date" min="{{ today()->toDateString() }}" wire:model="start_date" />
                <x-ui.select :label="__('Start time')" id="overtime-start-hour" wire:model="start_hour">
                    @foreach ($hours as $hour)
                        <option value="{{ $hour }}">{{ $hour }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input :label="__('End date')" id="overtime-end-date" type="date" min="{{ today()->toDateString() }}" wire:model="end_date" />
                <x-ui.select :label="__('End time')" id="overtime-end-hour" wire:model="end_hour">
                    @foreach ($hours as $hour)
                        <option value="{{ $hour }}">{{ $hour }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <p class="text-sm text-slate-600">{{ __('Whole hours in WIB, at most :count hours. The end may be on the next day.', ['count' => $maxHours]) }}</p>

            <x-ui.textarea :label="__('Reason')" id="overtime-reason" maxlength="1000" wire:model="overtime_reason" :hint="__('10 to 1000 characters.')" />

            @include('livewire.requests.partials.evidence-input', ['field' => 'overtime_evidence', 'inputId' => 'overtime-evidence'])
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="overtime-form-fields" loading="submitOvertime,overtime_evidence">{{ __('Submit request') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
