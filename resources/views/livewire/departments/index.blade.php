<x-ui.page :title="__('Departments')" :subtitle="__('Departments that can be given to employees. An inactive department stays on the employees who have it.')" icon="building">
    <x-slot:actions>
        <x-ui.button size="sm" wire:click="openCreate" loading="openCreate"><x-ui.icon name="plus" class="size-4" wire:loading.remove wire:target="openCreate" />{{ __('Add department') }}</x-ui.button>
    </x-slot:actions>

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    @if ($departments->isNotEmpty())
        {{-- Figures from the list already on the page (no extra query). --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3" data-department-stats>
            <x-ui.stat :label="__('Departments')" :value="$departments->count()" icon="building" />
            <x-ui.stat :label="__('Active')" :value="$departments->where('is_active', true)->count()" icon="check-circle" tone="green" />
            <x-ui.stat :label="__('Employees')" :value="$departments->sum('users_count')" icon="users" tone="teal" />
        </div>
    @endif

    <x-ui.card>
        @if ($departments->isEmpty())
            <x-ui.empty-state :title="__('No departments yet')" :description="__('Add the first department to assign it to employees.')" icon="building">
                <x-ui.button size="sm" wire:click="openCreate" loading="openCreate"><x-ui.icon name="plus" class="size-4" wire:loading.remove wire:target="openCreate" />{{ __('Add department') }}</x-ui.button>
            </x-ui.empty-state>
        @else
            <x-ui.table :caption="__('Departments')">
                <x-slot:head>
                    <tr>
                        <th scope="col">{{ __('Name') }}</th>
                        <th scope="col">{{ __('Status') }}</th>
                        <th scope="col">{{ __('Employees') }}</th>
                        <th scope="col" class="w-px"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </x-slot:head>

                @foreach ($departments as $department)
                    <tr wire:key="department-{{ $department->id }}">
                        <td>
                            <span class="flex items-center gap-3">
                                <x-ui.icon-chip icon="building" size="sm" :tone="$department->is_active ? 'primary' : 'gray'" />
                                <span class="font-medium text-foreground">{{ $department->name }}</span>
                            </span>
                        </td>
                        <td>
                            @if ($department->is_active)
                                <x-ui.badge tone="green" dot>{{ __('Active') }}</x-ui.badge>
                            @else
                                <x-ui.badge dot>{{ __('Inactive') }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="tabular-nums">{{ $department->users_count }}</td>
                        <td class="text-right">
                            <x-ui.row-actions :id="'department-actions-'.$department->id" :label="__('Actions for :name', ['name' => $department->name])">
                                <x-ui.dropdown-item wire:click="openEdit({{ $department->id }})" icon="pencil">{{ __('Edit') }}</x-ui.dropdown-item>
                                @if ($department->users_count === 0)
                                    <div class="-mx-1 my-1 h-px bg-border" role="none"></div>
                                    <x-ui.dropdown-item wire:click="openDelete({{ $department->id }})" icon="trash" variant="destructive">{{ __('Delete') }}</x-ui.dropdown-item>
                                @endif
                            </x-ui.row-actions>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.modal name="department-form" icon="building" :title="$targetId === null ? __('Add department') : __('Edit :name', ['name' => $targetName])">
        <form id="department-form-fields" class="space-y-4" wire:submit="save">
            <x-ui.input :label="__('Department name')" id="department-name" icon="building" maxlength="100" autocomplete="off" wire:model="name" />

            @if ($targetId !== null)
                <div class="flex items-start gap-3">
                    <input id="department-active" type="checkbox" class="mt-0.5 size-4 rounded border-input accent-primary" wire:model="active">
                    <div>
                        <label for="department-active" class="text-sm font-medium text-foreground">{{ __('Active') }}</label>
                        <p class="text-sm text-muted-foreground">{{ __('Only active departments can be given to employees.') }}</p>
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

    <x-ui.modal name="department-delete" icon="alert-triangle" tone="danger" :title="__('Delete :name?', ['name' => $targetName])">
        <p>{{ __('The department is removed from the list. This cannot be undone.') }}</p>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="danger" wire:click="delete" loading="delete">{{ __('Delete department') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
