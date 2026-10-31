<div class="space-y-6">
    <x-ui.page-header title="Departments" description="The departments users can be assigned to.">
        <x-slot:actions>
            <x-ui.button size="sm" wire:click="openCreate">Add department</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    <x-ui.card flush>
        @if ($departments->isEmpty())
            <x-ui.empty-state title="No departments yet" description="Add the first department to assign it to users." />
        @else
            <x-ui.table caption="Departments">
                <x-slot:head>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Status</th>
                        <th scope="col">Users</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                </x-slot:head>

                @foreach ($departments as $department)
                    <tr wire:key="department-{{ $department->id }}">
                        <td data-primary class="font-medium wrap-anywhere text-ink">{{ $department->name }}</td>
                        <td data-label="Status">
                            <x-ui.badge :tone="$department->is_active ? 'green' : 'gray'">{{ $department->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                        </td>
                        <td data-label="Users">{{ $department->users_count }}</td>
                        <td data-actions class="text-right">
                            <div class="flex flex-wrap justify-end gap-2">
                                <x-ui.button variant="secondary" size="sm" wire:click="openEdit({{ $department->id }})">Edit</x-ui.button>
                                <x-ui.button variant="secondary" size="sm" wire:click="toggleActive({{ $department->id }})">{{ $department->is_active ? 'Deactivate' : 'Activate' }}</x-ui.button>
                                @if ($department->users_count === 0)
                                    <x-ui.button variant="secondary" size="sm" wire:click="openDelete({{ $department->id }})">Delete</x-ui.button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.modal name="department-form" :title="$targetId === null ? 'Add department' : 'Edit '.$targetName">
        <form id="department-form-fields" wire:submit="save">
            <x-ui.input label="Name" id="department-name" maxlength="100" autocomplete="off" wire:model="name" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="department-form-fields" loading="save">Save</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="department-delete" :title="'Delete '.$targetName.'?'">
        <p>This removes the department for good.</p>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button variant="danger" wire:click="delete" loading="delete">Delete department</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
