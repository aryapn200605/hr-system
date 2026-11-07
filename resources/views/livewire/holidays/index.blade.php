<x-ui.page :title="__('Holidays')" :subtitle="__('Public holidays and collective leave days. They are not counted as working days in new requests; existing requests keep their working days.')" icon="flag">
    <x-slot:actions>
        <x-ui.button size="sm" wire:click="openCreate" loading="openCreate"><x-ui.icon name="plus" class="size-4" wire:loading.remove wire:target="openCreate" />{{ __('Add holiday') }}</x-ui.button>
    </x-slot:actions>

    <x-ui.card>
        <x-ui.toolbar>
            <x-ui.select :label="__('Year')" id="filter-year" wire:model.live="year"
                :options="collect($years)->mapWithKeys(fn ($option) => [(string) $option => (string) $option])->all()" />
        </x-ui.toolbar>

        <x-ui.table-skeleton target="year" :columns="4" :rows="4" />
        <div wire:loading.delay.remove wire:target="year">
            @if ($holidays->isEmpty())
                <x-ui.empty-state :title="__('No holidays in :year', ['year' => $shownYear])" :description="__('Add the public holidays and collective leave days of this year.')" icon="flag">
                    <x-ui.button size="sm" wire:click="openCreate" loading="openCreate"><x-ui.icon name="plus" class="size-4" wire:loading.remove wire:target="openCreate" />{{ __('Add holiday') }}</x-ui.button>
                </x-ui.empty-state>
            @else
                <x-ui.table :caption="__('Holidays')">
                    <x-slot:head>
                        <tr>
                            <th scope="col">{{ __('Date') }}</th>
                            <th scope="col">{{ __('Day') }}</th>
                            <th scope="col">{{ __('Name') }}</th>
                            <th scope="col" class="w-px"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </x-slot:head>

                    @foreach ($holidays as $holiday)
                        <tr wire:key="holiday-{{ $holiday->id }}">
                            <td data-label="{{ __('Date') }}">
                                <span class="flex items-center gap-3">
                                    {{-- A small calendar leaf: month above the day number (decorative, the date follows). --}}
                                    <span class="flex w-10 shrink-0 flex-col overflow-hidden rounded-md border border-border text-center leading-none shadow-xs" aria-hidden="true">
                                        <span class="bg-primary py-0.5 text-[0.625rem] font-semibold tracking-wide text-white uppercase">{{ $holiday->date->translatedFormat('M') }}</span>
                                        <span class="bg-card py-1 text-sm font-semibold text-foreground tabular-nums">{{ $holiday->date->format('j') }}</span>
                                    </span>
                                    <x-ui.date :value="$holiday->date" />
                                </span>
                            </td>
                            <td data-label="{{ __('Day') }}">{{ $holiday->date->translatedFormat('l') }}</td>
                            <td class="font-medium text-foreground whitespace-normal! min-w-48" data-card="title">{{ $holiday->name }}</td>
                            <td class="text-right" data-card="menu">
                                <x-ui.row-actions :id="'holiday-actions-'.$holiday->id" :label="__('Actions for :name', ['name' => $holiday->name])">
                                    <x-ui.dropdown-item wire:click="openEdit({{ $holiday->id }})" icon="pencil">{{ __('Edit') }}</x-ui.dropdown-item>
                                    <div class="-mx-1 my-1 h-px bg-border" role="none"></div>
                                    <x-ui.dropdown-item wire:click="openDelete({{ $holiday->id }})" icon="trash" variant="destructive">{{ __('Delete') }}</x-ui.dropdown-item>
                                </x-ui.row-actions>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>

            @endif
        </div>
    </x-ui.card>

    <x-ui.modal name="holiday-form" icon="calendar" :title="$targetId === null ? __('Add holiday') : __('Edit :name', ['name' => $targetName])">
        <form id="holiday-form-fields" class="space-y-4" wire:submit="save">
            <x-ui.date-picker :label="__('Date')" id="holiday-date" :min="$earliest" :max="$latest" wire:model="date" />
            <x-ui.input :label="__('Holiday name')" id="holiday-name" maxlength="100" autocomplete="off" wire:model="name" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="holiday-form-fields" loading="save">{{ $targetId === null ? __('Add holiday') : __('Save changes') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="holiday-delete" icon="alert-triangle" tone="danger" :title="__('Delete :name?', ['name' => $targetName])">
        <p>{{ __('The day counts as a working day again in new requests. Existing requests keep their working days.') }}</p>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="danger" wire:click="delete" loading="delete">{{ __('Delete holiday') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
