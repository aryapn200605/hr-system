<div class="space-y-6">
    <x-ui.page-header title="Public holidays" description="Days that do not count as working days. Requests already made keep their day counts.">
        <x-slot:actions>
            <x-ui.button size="sm" wire:click="openCreate">Add holiday</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card flush>
        @if ($holidays->isEmpty())
            <x-ui.empty-state title="No holidays yet" description="Add national holidays and collective leave days here." />
        @else
            <x-ui.table caption="Public holidays">
                <x-slot:head>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Name</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                </x-slot:head>

                @foreach ($holidays as $holiday)
                    <tr wire:key="holiday-{{ $holiday->id }}">
                        <td data-primary class="whitespace-nowrap">{{ $holiday->date->format('D, j M Y') }}</td>
                        <td data-label="Name" class="wrap-anywhere">{{ $holiday->name }}</td>
                        <td data-actions class="text-right">
                            <div class="flex flex-wrap justify-end gap-2">
                                <x-ui.button variant="secondary" size="sm" wire:click="openEdit({{ $holiday->id }})" :aria-label="'Edit '.$holiday->name">Edit</x-ui.button>
                                <x-ui.button variant="secondary" size="sm" wire:click="openDelete({{ $holiday->id }})" :aria-label="'Delete '.$holiday->name">Delete</x-ui.button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif

        @if ($holidays->total() > 0)
            <x-ui.pagination :paginator="$holidays" />
        @endif
    </x-ui.card>

    <x-ui.modal name="holiday-form" :title="$targetId === null ? 'Add holiday' : 'Edit '.$targetName">
        <form id="holiday-form-fields" class="space-y-4" wire:submit="save">
            <x-ui.input label="Date" id="holiday-date" type="date" wire:model="date" />
            <x-ui.input label="Name" id="holiday-name" maxlength="150" autocomplete="off" wire:model="name" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="holiday-form-fields" loading="save">Save</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="holiday-delete" :title="'Delete '.$targetName.'?'">
        <p>This date counts as a working day again for new requests. Requests already made do not change.</p>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button variant="danger" wire:click="delete" loading="delete">Delete holiday</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
