<div class="space-y-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ __('User Management') }}</h1>
            <p class="mt-2 text-sm text-slate-600">{{ __('Create accounts, change roles and control access.') }}</p>
        </div>

        @can('create', \App\Models\User::class)
            <x-ui.button size="sm" class="self-start" wire:click="openCreate" loading="openCreate">{{ __('Create user') }}</x-ui.button>
        @endcan
    </header>

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    <x-ui.card>
        <div class="grid grid-cols-1 gap-4 border-b border-slate-200 p-5 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.input :label="__('Search')" id="filter-search" type="search" maxlength="100" :placeholder="__('Name, username or email')" wire:model.live.debounce.300ms="search" />

            <x-ui.select :label="__('Role')" id="filter-role" wire:model.live="roleFilter">
                <option value="">{{ __('All roles') }}</option>
                @foreach ($roles as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Status')" id="filter-status" wire:model.live="statusFilter">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Rows per page')" id="filter-per-page" wire:model.live="perPage">
                @foreach ($perPageOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <div wire:loading.class="opacity-60" wire:target="search,roleFilter,statusFilter,perPage,setPage,nextPage,previousPage">
            @if ($users->isEmpty())
                <x-ui.empty-state :title="__('No users found')" :description="__('Try a different search or filter.')" icon="users" />
            @else
                <x-ui.table :caption="__('Users')">
                    <x-slot:head>
                        <tr>
                            <th scope="col">{{ __('ID') }}</th>
                            <th scope="col">{{ __('Full name') }}</th>
                            <th scope="col">{{ __('Username') }}</th>
                            <th scope="col">{{ __('Email') }}</th>
                            <th scope="col">{{ __('Role') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col">{{ __('2FA') }}</th>
                            <th scope="col">{{ __('Last login') }}</th>
                            <th scope="col">{{ __('Created') }}</th>
                            <th scope="col">{{ __('Actions') }}</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($users as $row)
                        <tr wire:key="user-{{ $row->id }}">
                            <td>{{ $row->id }}</td>
                            <td class="font-medium text-slate-900">{{ $row->name }}</td>
                            <td>{{ $row->username }}</td>
                            <td>{{ $row->email }}</td>
                            <td><x-ui.role-badge :user="$row" /></td>
                            <td><x-ui.status-badge :status="$row->status" /></td>
                            <td>
                                @if ($row->hasCompletedTwoFactorEnrollment())
                                    <x-ui.badge tone="green">{{ __('Enabled') }}</x-ui.badge>
                                @else
                                    <x-ui.badge>{{ __('Not enabled') }}</x-ui.badge>
                                @endif
                            </td>
                            <td>
                                @if ($row->last_login_at)
                                    <x-ui.datetime :value="$row->last_login_at" />
                                @else
                                    <span class="text-slate-600">{{ __('Never') }}</span>
                                @endif
                            </td>
                            <td><x-ui.datetime :value="$row->created_at" /></td>
                            <td class="text-right">
                                <x-ui.dropdown :id="'user-actions-'.$row->id" :label="__('Actions for :name', ['name' => $row->name])" anchored trigger-class="px-2.5 py-1.5" class="inline-block">
                                    <x-slot:trigger>
                                        <span>{{ __('Actions') }}</span>
                                        <x-ui.icon name="chevron-down" class="size-4" />
                                    </x-slot:trigger>

                                    <x-ui.dropdown-item :href="route('users.show', $row->id)" wire:navigate.off>{{ __('View details') }}</x-ui.dropdown-item>

                                    @can('update', $row)
                                        <x-ui.dropdown-item wire:click="openEdit({{ $row->id }})">{{ __('Edit') }}</x-ui.dropdown-item>
                                    @endcan

                                    @can('changeAnyRole', $row)
                                        <x-ui.dropdown-item wire:click="openRole({{ $row->id }})">{{ __('Change role') }}</x-ui.dropdown-item>
                                    @endcan

                                    @can('resendInvitation', $row)
                                        <x-ui.dropdown-item wire:click="openResend({{ $row->id }})">{{ __('Resend invitation') }}</x-ui.dropdown-item>
                                    @endcan

                                    @if ($row->status === \App\Enums\UserStatus::Suspended)
                                        @if ($row->activated)
                                            @can('reactivate', $row)
                                                <x-ui.dropdown-item wire:click="openReactivate({{ $row->id }})">{{ __('Reactivate') }}</x-ui.dropdown-item>
                                            @endcan
                                        @endif
                                    @else
                                        @can('suspend', $row)
                                            <x-ui.dropdown-item wire:click="openSuspend({{ $row->id }})" class="text-red-800!">{{ __('Suspend') }}</x-ui.dropdown-item>
                                        @endcan
                                    @endif
                                </x-ui.dropdown>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($users->total() > 0)
            <nav aria-label="{{ __('Pagination') }}" class="flex flex-col gap-3 border-t border-slate-200 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-slate-600">{{ __('Showing :first to :last of :total', ['first' => $users->firstItem(), 'last' => $users->lastItem(), 'total' => $users->total()]) }}</p>

                @if ($users->hasPages())
                    <div class="flex gap-2">
                        <x-ui.button variant="secondary" size="sm" wire:click="previousPage" loading="previousPage" :disabled="$users->onFirstPage()">{{ __('Previous') }}</x-ui.button>
                        <x-ui.button variant="secondary" size="sm" wire:click="nextPage" loading="nextPage" :disabled="! $users->hasMorePages()">{{ __('Next') }}</x-ui.button>
                    </div>
                @endif
            </nav>
        @endif
    </x-ui.card>

    <x-ui.modal name="user-form" :title="$targetId === null ? __('Create user') : __('Edit :name', ['name' => $targetName])">
        <form id="user-form-fields" class="space-y-4" wire:submit="saveUser">
            <x-ui.input :label="__('Full name')" id="form-name" autocomplete="off" wire:model="name" />
            <x-ui.input
                :label="__('Username')"
                id="form-username"
                autocomplete="off"
                wire:model="username"
                :readonly="$identityLocked"
                :hint="$identityLocked ? __('Change your own username in My Profile.') : null"
            />
            <x-ui.input
                :label="__('Email')"
                id="form-email"
                type="email"
                autocomplete="off"
                wire:model="email"
                :readonly="$identityLocked"
                :hint="$identityLocked ? __('Change your own email in My Profile.') : null"
            />

            @if ($targetId === null)
                <x-ui.select :label="__('Role')" id="form-role" wire:model="role">
                    <option value="">{{ __('Choose a role') }}</option>
                    @foreach ($assignableRoles as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>

                <p class="text-sm text-slate-600">{{ __('An invitation with an activation link is emailed to this address.') }}</p>
            @endif
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="user-form-fields" loading="saveUser">{{ $targetId === null ? __('Create and invite') : __('Save changes') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="user-role" :title="__('Change role of :name', ['name' => $targetName])">
        <form id="user-role-fields" wire:submit="saveRole">
            <x-ui.select :label="__('Role')" id="change-role" wire:model="role" :hint="__('The new permissions apply from the user\'s next request.')">
                @foreach ($assignableRoles as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="user-role-fields" loading="saveRole">{{ __('Change role') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="user-confirm" :title="match ($confirming) {
        'suspend' => __('Suspend :name?', ['name' => $targetName]),
        'reactivate' => __('Reactivate :name?', ['name' => $targetName]),
        'resend' => __('Resend invitation?'),
        default => __('Confirm'),
    }">
        @if ($confirming === 'suspend')
            <p>{{ __('This user is signed out at once and cannot sign in. Any open invitation stops working.') }}</p>
        @elseif ($confirming === 'reactivate')
            <p>{{ __('This user can sign in again with their existing password and two-factor authentication. Their role does not change.') }}</p>
        @elseif ($confirming === 'resend')
            <p>{{ __('A new invitation is emailed to :name. The earlier link stops working at once.', ['name' => $targetName]) }}</p>
        @endif

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button :variant="$confirming === 'suspend' ? 'danger' : 'primary'" wire:click="confirm" loading="confirm">
                {{ match ($confirming) {
                    'suspend' => __('Suspend user'),
                    'reactivate' => __('Reactivate user'),
                    default => __('Send invitation'),
                } }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
