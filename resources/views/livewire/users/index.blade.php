<x-ui.page :title="__('User Management')" :subtitle="__('Create accounts, change roles and control access.')" icon="users">
    @can('create', \App\Models\User::class)
        <x-slot:actions>
            <x-ui.button size="sm" wire:click="openCreate" loading="openCreate"><x-ui.icon name="user-plus" class="size-4" wire:loading.remove wire:target="openCreate" />{{ __('Create user') }}</x-ui.button>
        </x-slot:actions>
    @endcan

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    @php
        $filtered = $search !== '' || $roleFilter !== [] || $statusFilter !== [] || $supervisorFilter !== '';
        // Clears the four filters in one request: the first three $set calls wait for the last one.
        $clearFilters = "\$wire.\$set('search', '', false); \$wire.\$set('roleFilter', [], false); \$wire.\$set('statusFilter', [], false); \$wire.\$set('supervisorFilter', '')";
        $loadingTarget = 'search,roleFilter,statusFilter,supervisorFilter,perPage,setPage,gotoPage,nextPage,previousPage';
    @endphp

    <x-ui.card>
        <x-ui.toolbar>
            <x-ui.input :label="__('Search')" id="filter-search" icon="search" type="search" maxlength="100" :placeholder="__('Name, username or email')" wire:model.live.debounce.300ms="search" />

            {{-- Several values in one filter: any of them; several filters: all of them. --}}
            <x-ui.select :label="__('Role')" id="filter-role" multiple :placeholder="__('All roles')" wire:model.live="roleFilter"
                :options="collect($roles)->mapWithKeys(fn ($option) => [$option->value => $option->label()])->all()" />

            <x-ui.select :label="__('Status')" id="filter-status" multiple :placeholder="__('All statuses')" wire:model.live="statusFilter"
                :options="collect($statuses)->mapWithKeys(fn ($option) => [$option->value => $option->label()])->all()" />

            <x-ui.select :label="__('Supervisor')" id="filter-supervisor" wire:model.live="supervisorFilter"
                :options="['' => __('Any'), 'none' => __('No supervisor')]" />

            @if ($filtered)
                <x-slot:actions>
                    <x-ui.button variant="ghost" size="sm" x-on:click="{{ $clearFilters }}" data-clear-filters>
                        <x-ui.icon name="close" class="size-4" />{{ __('Clear filters') }}
                    </x-ui.button>
                </x-slot:actions>
            @endif
        </x-ui.toolbar>

        <x-ui.table-skeleton :target="$loadingTarget" :columns="6" :rows="min((int) $perPage, 8)" />
        <div wire:loading.delay.remove wire:target="{{ $loadingTarget }}">
            @if ($users->isEmpty())
                <x-ui.empty-state :title="__('No users found')" :description="__('Try a different search or filter.')" icon="users">
                    @if ($filtered)
                        <x-ui.button variant="secondary" size="sm" x-on:click="{{ $clearFilters }}">{{ __('Clear filters') }}</x-ui.button>
                    @endif
                </x-ui.empty-state>
            @else
                <x-ui.table :caption="__('Users')">
                    <x-slot:head>
                        <tr>
                            <th scope="col">{{ __('ID') }}</th>
                            <th scope="col">{{ __('Full name') }}</th>
                            <th scope="col">{{ __('Username') }}</th>
                            <th scope="col">{{ __('Email') }}</th>
                            <th scope="col">{{ __('Department') }}</th>
                            <th scope="col">{{ __('Role') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col">{{ __('2FA') }}</th>
                            <th scope="col">{{ __('Last login') }}</th>
                            <th scope="col">{{ __('Created') }}</th>
                            <th scope="col" class="w-px"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </x-slot:head>

                    @foreach ($users as $row)
                        <tr wire:key="user-{{ $row->id }}">
                            <td class="text-muted-foreground tabular-nums">{{ $row->id }}</td>
                            <td>
                                <span class="flex items-center gap-3">
                                    <x-ui.avatar :name="$row->name" class="bg-primary/10 text-primary-hover" />
                                    <span class="font-medium text-foreground">{{ $row->name }}</span>
                                </span>
                            </td>
                            <td class="text-muted-foreground">{{ $row->username }}</td>
                            <td class="text-muted-foreground">{{ $row->email }}</td>
                            <td>{{ $row->department?->name ?? '—' }}</td>
                            <td>
                                <x-ui.role-badge :user="$row" />
                                @if ($row->needsSupervisor() && $row->status !== \App\Enums\UserStatus::Suspended && ! $row->supervised)
                                    <x-ui.badge tone="amber" class="ml-1" data-no-supervisor>{{ __('No supervisor') }}</x-ui.badge>
                                @endif
                            </td>
                            <td><x-ui.status-badge :status="$row->status" dot /></td>
                            <td>
                                @if ($row->hasCompletedTwoFactorEnrollment())
                                    <x-ui.badge tone="green" dot>{{ __('Enabled') }}</x-ui.badge>
                                @else
                                    <x-ui.badge dot>{{ __('Not enabled') }}</x-ui.badge>
                                @endif
                            </td>
                            <td>
                                @if ($row->last_login_at)
                                    <x-ui.datetime :value="$row->last_login_at" />
                                @else
                                    <span class="text-muted-foreground">{{ __('Never') }}</span>
                                @endif
                            </td>
                            <td><x-ui.datetime :value="$row->created_at" /></td>
                            <td class="text-right">
                                <x-ui.row-actions :id="'user-actions-'.$row->id" :label="__('Actions for :name', ['name' => $row->name])">
                                    <x-ui.dropdown-item :href="route('users.show', $row->id)" icon="eye" wire:navigate.off>{{ __('View details') }}</x-ui.dropdown-item>

                                    @can('update', $row)
                                        <x-ui.dropdown-item wire:click="openEdit({{ $row->id }})" icon="pencil">{{ __('Edit') }}</x-ui.dropdown-item>
                                    @endcan

                                    @can('changeAnyRole', $row)
                                        <x-ui.dropdown-item wire:click="openRole({{ $row->id }})" icon="shield">{{ __('Change role') }}</x-ui.dropdown-item>
                                    @endcan

                                    @can('resendInvitation', $row)
                                        <x-ui.dropdown-item wire:click="openResend({{ $row->id }})" icon="mail">{{ __('Resend invitation') }}</x-ui.dropdown-item>
                                    @endcan

                                    @if ($row->status === \App\Enums\UserStatus::Suspended)
                                        @if ($row->activated)
                                            @can('reactivate', $row)
                                                <x-ui.dropdown-item wire:click="openReactivate({{ $row->id }})" icon="user-check">{{ __('Reactivate') }}</x-ui.dropdown-item>
                                            @endcan
                                        @endif
                                    @else
                                        @can('suspend', $row)
                                            <div class="-mx-1 my-1 h-px bg-border" role="none"></div>
                                            <x-ui.dropdown-item wire:click="openSuspend({{ $row->id }})" icon="user-x" variant="destructive">{{ __('Suspend') }}</x-ui.dropdown-item>
                                        @endcan
                                    @endif
                                </x-ui.row-actions>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        <x-ui.pagination :paginator="$users">
            <div class="flex items-center gap-2">
                <span class="text-sm font-medium whitespace-nowrap" aria-hidden="true">{{ __('Rows per page') }}</span>
                <x-ui.select :label="__('Rows per page')" id="filter-per-page" class="w-20 [&>button]:mt-0 [&>label]:sr-only" wire:model.live="perPage"
                    :options="collect($perPageOptions)->mapWithKeys(fn ($option) => [$option => $option])->all()" />
            </div>
        </x-ui.pagination>
    </x-ui.card>

    <x-ui.modal name="user-form" icon="user" :title="$targetId === null ? __('Create user') : __('Edit :name', ['name' => $targetName])">
        <form id="user-form-fields" class="space-y-4" wire:submit="saveUser">
            <x-ui.input :label="__('Full name')" id="form-name" icon="user" autocomplete="off" wire:model="name" />
            <x-ui.input
                :label="__('Username')"
                id="form-username"
                icon="user"
                autocomplete="off"
                wire:model="username"
                :readonly="$identityLocked"
                :hint="$identityLocked ? __('Change your own username in My Profile.') : null"
            />
            <x-ui.input
                :label="__('Email')"
                id="form-email"
                icon="mail"
                type="email"
                autocomplete="off"
                wire:model="email"
                :readonly="$identityLocked"
                :hint="$identityLocked ? __('Change your own email in My Profile.') : null"
            />

            @if ($targetId === null)
                <x-ui.select :label="__('Role')" id="form-role" wire:model="role" :placeholder="__('Choose a role')"
                    :options="collect($assignableRoles)->mapWithKeys(fn ($option) => [$option->value => $option->label()])->all()" />

                <p class="text-sm text-slate-600">{{ __('An invitation with an activation link is emailed to this address.') }}</p>
            @endif
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="user-form-fields" loading="saveUser">{{ $targetId === null ? __('Create and invite') : __('Save changes') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="user-role" icon="shield" :title="__('Change role of :name', ['name' => $targetName])">
        <form id="user-role-fields" wire:submit="saveRole">
            <x-ui.select :label="__('Role')" id="change-role" wire:model="role" :hint="__('The new permissions apply from the user\'s next request.')"
                :options="collect($assignableRoles)->mapWithKeys(fn ($option) => [$option->value => $option->label()])->all()" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="user-role-fields" loading="saveRole">{{ __('Change role') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="user-confirm" :icon="match ($confirming) { 'suspend' => 'alert-triangle', 'reactivate' => 'check-circle', 'resend' => 'mail', default => null }" :tone="$confirming === 'suspend' ? 'danger' : 'default'" :title="match ($confirming) {
        'suspend' => __('Suspend :name?', ['name' => $targetName]),
        'reactivate' => __('Reactivate :name?', ['name' => $targetName]),
        'resend' => __('Resend invitation?'),
        default => __('Confirm'),
    }">
        @if ($confirming === 'suspend')
            <p>{{ __('This user is signed out at once and cannot sign in. Any open invitation stops working.') }}</p>
            <p class="mt-3" data-pending-requests="{{ $pendingRequests }}">
                @if ($pendingRequests > 0)
                    {{ __(':count pending requests of this user (leave and overtime) will be rejected automatically. Approved requests stay approved. Reactivating the user does not restore them.', ['count' => $pendingRequests]) }}
                @else
                    {{ __('This user has no pending requests.') }}
                @endif
            </p>
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
</x-ui.page>
