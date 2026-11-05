<x-ui.page :title="__('Departments')" :subtitle="__('Departments that can be given to employees. An inactive department stays on the employees who have it.')">
    <x-slot:actions>
        <x-ui.button size="sm" wire:click="openCreate" loading="openCreate">{{ __('Add department') }}</x-ui.button>
    </x-slot:actions>

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    <x-ui.card>
        @if ($departments->isEmpty())
            <x-ui.empty-state :title="__('No departments yet')" :description="__('Add the first department to assign it to employees.')" />
        @else
            <x-ui.table :caption="__('Departments')">
                <x-slot:head>
                    <tr>
                        <th scope="col">{{ __('Name') }}</th>
                        <th scope="col">{{ __('Status') }}</th>
                        <th scope="col">{{ __('Employees') }}</th>
                        <th scope="col">{{ __('Actions') }}</th>
                    </tr>
                </x-slot:head>

                @foreach ($departments as $department)
                    <tr wire:key="department-{{ $department->id }}">
                        <td class="font-medium text-slate-900">{{ $department->name }}</td>
                        <td>
                            @if ($department->is_active)
                                <x-ui.badge tone="green">{{ __('Active') }}</x-ui.badge>
                            @else
                                <x-ui.badge>{{ __('Inactive') }}</x-ui.badge>
                            @endif
                        </td>
                        <td>{{ $department->users_count }}</td>
                        <td class="text-right">
                            <div class="inline-flex gap-2">
                                <x-ui.button variant="secondary" size="sm" wire:click="openEdit({{ $department->id }})" loading="openEdit({{ $department->id }})">{{ __('Edit') }}</x-ui.button>
                                @if ($department->users_count === 0)
                                    <x-ui.button variant="secondary" size="sm" class="text-red-800!" wire:click="openDelete({{ $department->id }})" loading="openDelete({{ $department->id }})">{{ __('Delete') }}</x-ui.button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.modal name="department-form" :title="$targetId === null ? __('Add department') : __('Edit :name', ['name' => $targetName])">
        <form id="department-form-fields" class="space-y-4" wire:submit="save">
            <x-ui.input :label="__('Department name')" id="department-name" maxlength="100" autocomplete="off" wire:model="name" />

            @if ($targetId !== null)
                <div class="flex items-start gap-3">
                    <input id="department-active" type="checkbox" class="mt-0.5 size-4 rounded border-slate-500 text-blue-700" wire:model="active">
                    <div>
                        <label for="department-active" class="text-sm font-medium text-slate-800">{{ __('Active') }}</label>
                        <p class="text-sm text-slate-600">{{ __('Only active departments can be given to employees.') }}</p>
                    </div>
                </div>
                <x-ui.error for="active" id="department-active-error" />
            @endif
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="department-form-fields" loading="save">{{ $targetId === null ? __('Add department') : __('Save changes') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="department-delete" :title="__('Delete :name?', ['name' => $targetName])">
        <p>{{ __('The department is removed from the list. This cannot be undone.') }}</p>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="danger" wire:click="delete" loading="delete">{{ __('Delete department') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
