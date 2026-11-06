<x-ui.page :title="__('Holidays')" :subtitle="__('Public holidays and collective leave days. They are not counted as working days in new requests; existing requests keep their working days.')">
    <x-slot:actions>
        <x-ui.button size="sm" wire:click="openCreate" loading="openCreate">{{ __('Add holiday') }}</x-ui.button>
    </x-slot:actions>

    <x-ui.card>
        <div class="grid grid-cols-1 gap-4 border-b border-slate-200 p-5 sm:grid-cols-3">
            <x-ui.select :label="__('Year')" id="filter-year" wire:model.live="year"
                :options="collect($years)->mapWithKeys(fn ($option) => [(string) $option => (string) $option])->all()" />
        </div>

        @if ($holidays->isEmpty())
            <x-ui.empty-state :title="__('No holidays in :year', ['year' => $shownYear])" :description="__('Add the public holidays and collective leave days of this year.')" icon="calendar" />
        @else
            <x-ui.table :caption="__('Holidays')">
                <x-slot:head>
                    <tr>
                        <th scope="col">{{ __('Date') }}</th>
                        <th scope="col">{{ __('Day') }}</th>
                        <th scope="col">{{ __('Name') }}</th>
                        <th scope="col">{{ __('Actions') }}</th>
                    </tr>
                </x-slot:head>

                @foreach ($holidays as $holiday)
                    <tr wire:key="holiday-{{ $holiday->id }}">
                        <td><x-ui.date :value="$holiday->date" /></td>
                        <td>{{ $holiday->date->translatedFormat('l') }}</td>
                        <td class="font-medium text-slate-900 whitespace-normal! min-w-48">{{ $holiday->name }}</td>
                        <td class="text-right">
                            <div class="inline-flex gap-2">
                                <x-ui.button variant="secondary" size="sm" wire:click="openEdit({{ $holiday->id }})" loading="openEdit({{ $holiday->id }})">{{ __('Edit') }}</x-ui.button>
                                <x-ui.button variant="secondary" size="sm" class="text-red-800!" wire:click="openDelete({{ $holiday->id }})" loading="openDelete({{ $holiday->id }})">{{ __('Delete') }}</x-ui.button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.modal name="holiday-form" :title="$targetId === null ? __('Add holiday') : __('Edit :name', ['name' => $targetName])">
        <form id="holiday-form-fields" class="space-y-4" wire:submit="save">
            <x-ui.date-picker :label="__('Date')" id="holiday-date" :min="$earliest" :max="$latest" wire:model="date" />
            <x-ui.input :label="__('Holiday name')" id="holiday-name" maxlength="100" autocomplete="off" wire:model="name" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="holiday-form-fields" loading="save">{{ $targetId === null ? __('Add holiday') : __('Save changes') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="holiday-delete" :title="__('Delete :name?', ['name' => $targetName])">
        <p>{{ __('The day counts as a working day again in new requests. Existing requests keep their working days.') }}</p>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="danger" wire:click="delete" loading="delete">{{ __('Delete holiday') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
