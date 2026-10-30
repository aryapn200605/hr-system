<div class="space-y-8">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">User Management</h1>
            <p class="mt-2 text-sm text-muted">Create accounts, change roles and control access.</p>
        </div>

        @can('create', \App\Models\User::class)
            <x-ui.button size="sm" class="self-start" wire:click="openCreate">Create user</x-ui.button>
        @endcan
    </header>

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    <x-ui.card>
        <div class="grid grid-cols-2 gap-3 border-b border-rule py-3 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]">
            <div class="col-span-2 lg:col-span-1">
                <x-ui.input label="Search" id="filter-search" type="search" maxlength="100" placeholder="Name, username or email" wire:model.live.debounce.300ms="search" />
            </div>

            <x-ui.select label="Role" id="filter-role" wire:model.live="roleFilter">
                <option value="">All roles</option>
                @foreach ($roles as $option)
                    <option value="{{ $option->value }}">{{ $option->value }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select label="Status" id="filter-status" wire:model.live="statusFilter">
                <option value="">All statuses</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select label="Rows per page" id="filter-per-page" wire:model.live="perPage">
                @foreach ($perPageOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <div wire:loading.class="opacity-60" wire:target="search,roleFilter,statusFilter,perPage,setPage,nextPage,previousPage">
            @if ($users->isEmpty())
                <x-ui.empty-state title="No users found" description="No account matches this search. Clear the search box, or set Role and Status back to All." />
            @else
                <x-ui.table caption="Users">
                    <x-slot:head>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Full name</th>
                            <th scope="col">Username</th>
                            <th scope="col">Email</th>
                            <th scope="col">Role</th>
                            <th scope="col">Status</th>
                            <th scope="col">2FA</th>
                            <th scope="col">Last login</th>
                            <th scope="col">Created</th>
                            <th scope="col" class="sm:sticky sm:right-0 sm:border-l sm:border-rule sm:bg-paper sm:text-right">Actions</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($users as $row)
                        <tr wire:key="user-{{ $row->id }}">
                            <td data-label="ID">{{ $row->id }}</td>
                            <td data-primary class="font-medium text-ink sm:max-w-40 sm:truncate" title="{{ $row->name }}">{{ $row->name }}</td>
                            <td data-label="Username">{{ $row->username }}</td>
                            <td data-label="Email" title="{{ $row->email }}" class="sm:max-w-32 sm:truncate 2xl:max-w-none">{{ $row->email }}</td>
                            <td data-label="Role"><x-ui.role-badge :user="$row" /></td>
                            <td data-label="Status"><x-ui.status-badge :status="$row->status" /></td>
                            <td data-label="2FA">
                                @if ($row->hasCompletedTwoFactorEnrollment())
                                    <x-ui.badge tone="green">Enabled</x-ui.badge>
                                @else
                                    <x-ui.badge>Not enabled</x-ui.badge>
                                @endif
                            </td>
                            <td data-label="Last login">
                                @if ($row->last_login_at)
                                    <x-ui.datetime :value="$row->last_login_at" compact />
                                @else
                                    <span class="text-muted">Never</span>
                                @endif
                            </td>
                            <td data-label="Created"><x-ui.datetime :value="$row->created_at" compact /></td>
                            <td data-actions class="sm:sticky sm:right-0 sm:border-l sm:border-rule sm:bg-paper sm:text-right">
                                <x-ui.dropdown :id="'user-actions-'.$row->id" :label="'Actions for '.$row->name" anchored trigger-class="px-2 py-1" class="inline-block">
                                    <x-slot:trigger>
                                        <span>Actions</span>
                                        <x-ui.icon name="chevron-down" class="size-3.5" />
                                    </x-slot:trigger>

                                    <x-ui.dropdown-item :href="route('users.show', $row->id)" wire:navigate.off>View details</x-ui.dropdown-item>

                                    @can('update', $row)
                                        <x-ui.dropdown-item wire:click="openEdit({{ $row->id }})">Edit</x-ui.dropdown-item>
                                    @endcan

                                    @can('changeAnyRole', $row)
                                        <x-ui.dropdown-item wire:click="openRole({{ $row->id }})">Change role</x-ui.dropdown-item>
                                    @endcan

                                    @can('resendInvitation', $row)
                                        <x-ui.dropdown-item wire:click="openResend({{ $row->id }})">Resend invitation</x-ui.dropdown-item>
                                    @endcan

                                    @if ($row->status === \App\Enums\UserStatus::Suspended)
                                        @if ($row->activated)
                                            @can('reactivate', $row)
                                                <x-ui.dropdown-item wire:click="openReactivate({{ $row->id }})">Reactivate</x-ui.dropdown-item>
                                            @endcan
                                        @endif
                                    @else
                                        @can('suspend', $row)
                                            <x-ui.dropdown-item wire:click="openSuspend({{ $row->id }})" class="text-tone-red!">Suspend</x-ui.dropdown-item>
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
            <nav aria-label="Pagination" class="flex flex-col gap-3 border-t border-rule py-2.5 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-muted">Showing {{ $users->firstItem() }} to {{ $users->lastItem() }} of {{ $users->total() }}</p>

                @if ($users->hasPages())
                    <div class="flex gap-2">
                        <x-ui.button variant="secondary" size="sm" wire:click="previousPage" loading="previousPage" :disabled="$users->onFirstPage()">Previous</x-ui.button>
                        <x-ui.button variant="secondary" size="sm" wire:click="nextPage" loading="nextPage" :disabled="! $users->hasMorePages()">Next</x-ui.button>
                    </div>
                @endif
            </nav>
        @endif
    </x-ui.card>

    <x-ui.modal name="user-form" :title="$targetId === null ? 'Create user' : 'Edit '.$targetName">
        <form id="user-form-fields" class="space-y-4" wire:submit="saveUser">
            <x-ui.input label="Full name" id="form-name" autocomplete="off" wire:model="name" />
            <x-ui.input
                label="Username"
                id="form-username"
                autocomplete="off"
                wire:model="username"
                :readonly="$identityLocked"
                :hint="$identityLocked ? 'Change your own username in My Profile.' : null"
            />
            <x-ui.input
                label="Email"
                id="form-email"
                type="email"
                autocomplete="off"
                wire:model="email"
                :readonly="$identityLocked"
                :hint="$identityLocked ? 'Change your own email in My Profile.' : null"
            />

            @if ($targetId === null)
                <x-ui.select label="Role" id="form-role" wire:model="role">
                    <option value="">Choose a role</option>
                    @foreach ($assignableRoles as $option)
                        <option value="{{ $option->value }}">{{ $option->value }}</option>
                    @endforeach
                </x-ui.select>

                <p class="text-sm text-muted">An invitation with an activation link is emailed to this address.</p>
            @endif
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="user-form-fields" loading="saveUser">{{ $targetId === null ? 'Create and invite' : 'Save changes' }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="user-role" :title="'Change role of '.$targetName">
        <form id="user-role-fields" wire:submit="saveRole">
            <x-ui.select label="Role" id="change-role" wire:model="role" hint="The new permissions apply from the user's next request.">
                @foreach ($assignableRoles as $option)
                    <option value="{{ $option->value }}">{{ $option->value }}</option>
                @endforeach
            </x-ui.select>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="user-role-fields" loading="saveRole">Change role</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="user-confirm" :title="match ($confirming) {
        'suspend' => 'Suspend '.$targetName.'?',
        'reactivate' => 'Reactivate '.$targetName.'?',
        'resend' => 'Resend invitation?',
        default => 'Confirm',
    }">
        @if ($confirming === 'suspend')
            <p>This user is signed out at once and cannot sign in. Any open invitation stops working.</p>
        @elseif ($confirming === 'reactivate')
            <p>This user can sign in again with their existing password and two-factor authentication. Their role does not change.</p>
        @elseif ($confirming === 'resend')
            <p>A new invitation is emailed to {{ $targetName }}. The earlier link stops working at once.</p>
        @endif

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button :variant="$confirming === 'suspend' ? 'danger' : 'primary'" wire:click="confirm" loading="confirm">
                {{ match ($confirming) {
                    'suspend' => 'Suspend user',
                    'reactivate' => 'Reactivate user',
                    default => 'Send invitation',
                } }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
