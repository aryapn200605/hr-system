<div class="space-y-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold tracking-tight wrap-anywhere text-slate-900">{{ $user->name }}</h1>
            <p class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                <x-ui.role-badge :user="$user" />
                <x-ui.status-badge :status="$user->status" />
                @if ($missingSupervisor)
                    <x-ui.badge tone="amber" data-no-supervisor>{{ __('No supervisor') }}</x-ui.badge>
                @endif
            </p>
        </div>

        <div class="flex flex-wrap gap-2 self-start">
            @can('viewLeaveBalance', $user)
                <x-ui.button variant="secondary" size="sm" :href="route('users.leave-balance', $user->id)">{{ __('Leave balance') }}</x-ui.button>
            @endcan
            <x-ui.button variant="secondary" size="sm" :href="route('users.index')">{{ __('Back to users') }}</x-ui.button>
        </div>
    </header>

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Account information')">
            <dl class="divide-y divide-slate-200 px-5 py-2">
                <x-ui.detail :label="__('ID')">{{ $user->id }}</x-ui.detail>
                <x-ui.detail :label="__('Full name')">{{ $user->name }}</x-ui.detail>
                <x-ui.detail :label="__('Username')">{{ $user->username }}</x-ui.detail>
                <x-ui.detail :label="__('Email')">{{ $user->email }}</x-ui.detail>
                <x-ui.detail :label="__('Role')"><x-ui.role-badge :user="$user" /></x-ui.detail>
                <x-ui.detail :label="__('Status')"><x-ui.status-badge :status="$user->status" /></x-ui.detail>
                <x-ui.detail :label="__('Created')"><x-ui.datetime :value="$user->created_at" /></x-ui.detail>
            </dl>
        </x-ui.card>

        <x-ui.card :title="__('Security')">
            <dl class="divide-y divide-slate-200 px-5 py-2">
                <x-ui.detail :label="__('Two-factor authentication')">
                    @if ($user->hasCompletedTwoFactorEnrollment())
                        <x-ui.badge tone="green">{{ __('Enabled') }}</x-ui.badge>
                    @else
                        <x-ui.badge>{{ __('Not enabled') }}</x-ui.badge>
                    @endif
                </x-ui.detail>
                @if ($user->two_factor_enrolled_at)
                    <x-ui.detail :label="__('Enabled on')"><x-ui.datetime :value="$user->two_factor_enrolled_at" /></x-ui.detail>
                @endif
                <x-ui.detail :label="__('Last login')">
                    @if ($user->last_login_at)
                        <x-ui.datetime :value="$user->last_login_at" />
                    @else
                        {{ __('Never') }}
                    @endif
                </x-ui.detail>
            </dl>
        </x-ui.card>

        <x-ui.card :title="__('Employment')">
            <dl class="divide-y divide-slate-200 px-5 py-2">
                <x-ui.detail :label="__('Department')">
                    @if ($user->department)
                        {{ $user->department->name }}
                        @unless ($user->department->is_active)
                            <x-ui.badge class="ml-1">{{ __('Inactive') }}</x-ui.badge>
                        @endunless
                    @else
                        <span class="font-normal text-slate-600">{{ __('None') }}</span>
                    @endif
                </x-ui.detail>
                <x-ui.detail :label="__('Join date')">
                    @if ($user->join_date)
                        <x-ui.date :value="$user->join_date" />
                    @else
                        <span class="font-normal text-slate-600">{{ __('Not set') }}</span>
                    @endif
                </x-ui.detail>
            </dl>

            @if ($canUpdate)
                <div class="border-t border-slate-200 px-5 py-3.5">
                    <x-ui.button variant="secondary" size="sm" wire:click="openEmployment" loading="openEmployment">{{ __('Edit employment') }}</x-ui.button>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Supervisors')">
            @if ($supervisors->isEmpty())
                <p class="px-5 py-4 text-sm text-slate-600">
                    {{ $user->needsSupervisor() ? __('No supervisor yet. This user cannot submit requests until one is added.') : __('No supervisor. Approvers do not need one: their requests go to any HR Admin.') }}
                </p>
            @else
                <ul class="divide-y divide-slate-200">
                    @foreach ($supervisors as $supervisor)
                        <li class="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="supervisor-{{ $supervisor->id }}">
                            <div class="min-w-0">
                                <a href="{{ route('users.show', $supervisor->id) }}" class="text-sm font-medium wrap-anywhere text-blue-700 underline-offset-2 hover:underline">{{ $supervisor->name }}</a>
                                <p class="mt-1 flex flex-wrap items-center gap-2">
                                    <x-ui.status-badge :status="$supervisor->status" />
                                    @unless (in_array($supervisor->id, $counted, true))
                                        <x-ui.badge tone="amber">{{ __('Not counted while not an active Approver') }}</x-ui.badge>
                                    @endunless
                                </p>
                            </div>

                            @if ($canManage)
                                <x-ui.button variant="secondary" size="sm" class="shrink-0 self-start sm:self-auto" wire:click="removeSupervisor({{ $supervisor->id }})" loading="removeSupervisor({{ $supervisor->id }})">{{ __('Remove') }}</x-ui.button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($canManage)
                <div class="border-t border-slate-200 px-5 py-3.5">
                    <x-ui.button variant="secondary" size="sm" wire:click="openAddSupervisor" loading="openAddSupervisor">{{ __('Add supervisor') }}</x-ui.button>
                </div>
            @endif
        </x-ui.card>

        @if ($user->roleName() === \App\Enums\RoleName::Approver->value || $subordinates->isNotEmpty())
            <x-ui.card :title="__('Subordinates')" class="lg:col-span-2">
                @if ($subordinates->isEmpty())
                    <p class="px-5 py-4 text-sm text-slate-600">{{ __('Nobody has this user as supervisor.') }}</p>
                @else
                    <ul class="divide-y divide-slate-200">
                        @foreach ($subordinates as $subordinate)
                            <li class="flex flex-wrap items-center gap-2 px-5 py-3" wire:key="subordinate-{{ $subordinate->id }}">
                                <a href="{{ route('users.show', $subordinate->id) }}" class="text-sm font-medium wrap-anywhere text-blue-700 underline-offset-2 hover:underline">{{ $subordinate->name }}</a>
                                <x-ui.role-badge :user="$subordinate" />
                                <x-ui.status-badge :status="$subordinate->status" />
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canManage && $subordinates->isNotEmpty())
                    <div class="border-t border-slate-200 px-5 py-3.5">
                        <x-ui.button variant="secondary" size="sm" wire:click="openMove" loading="openMove">{{ __('Move subordinates') }}</x-ui.button>
                    </div>
                @endif
            </x-ui.card>
        @endif
    </div>

    <x-ui.modal name="employment-form" :title="__('Edit employment')">
        <form id="employment-form-fields" class="space-y-4" wire:submit="saveEmployment">
            <x-ui.select :label="__('Department')" id="employment-department" wire:model="departmentId">
                <option value="">{{ __('None') }}</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}">{{ $department->is_active ? $department->name : __(':name (inactive)', ['name' => $department->name]) }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.input :label="__('Join date')" id="employment-join-date" type="date" min="{{ \App\Actions\UpdateEmployment::EARLIEST_JOIN_DATE }}" wire:model="joinDate" :hint="__('For information only. Leave periods follow the anchor date on the leave balance page.')" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="employment-form-fields" loading="saveEmployment">{{ __('Save changes') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="supervisor-add" :title="__('Add supervisor')">
        <form id="supervisor-add-fields" class="space-y-4" wire:submit="addSupervisor">
            @if ($candidates->isEmpty())
                <p>{{ __('There is no other active Approver to add.') }}</p>
            @else
                <x-ui.select :label="__('Supervisor')" id="supervisor-choice" wire:model="supervisor" :hint="__('Only active Approvers can supervise.')">
                    <option value="">{{ __('Choose a supervisor') }}</option>
                    @foreach ($candidates as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->username }})</option>
                    @endforeach
                </x-ui.select>
            @endif
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="supervisor-add-fields" loading="addSupervisor" :disabled="$candidates->isEmpty()">{{ __('Add supervisor') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="subordinates-move" :title="__('Move subordinates')">
        <form id="subordinates-move-fields" class="space-y-4" wire:submit="moveSubordinates">
            <x-ui.select :label="__('New supervisor')" id="move-target" wire:model="target">
                <option value="">{{ __('Choose a supervisor') }}</option>
                @foreach ($targets as $option)
                    <option value="{{ $option->id }}">{{ $option->name }} ({{ $option->username }})</option>
                @endforeach
            </x-ui.select>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium text-slate-800">{{ __('Who moves') }}</legend>

                <label class="flex items-center gap-2 text-sm text-slate-800">
                    <input id="move-all" type="checkbox" class="size-4 rounded border-slate-500 text-blue-700" wire:model.live="moveAll">
                    {{ __('All subordinates') }}
                </label>

                @unless ($moveAll)
                    <div class="max-h-56 space-y-2 overflow-y-auto rounded-lg border border-slate-200 p-3">
                        @foreach ($subordinates as $subordinate)
                            <label class="flex items-center gap-2 text-sm text-slate-800" wire:key="move-{{ $subordinate->id }}">
                                <input type="checkbox" class="size-4 rounded border-slate-500 text-blue-700" value="{{ $subordinate->id }}" wire:model="selection">
                                <span class="wrap-anywhere">{{ $subordinate->name }}</span>
                            </label>
                        @endforeach
                    </div>
                @endunless

                <x-ui.error for="selection" id="move-selection-error" />
            </fieldset>

            <p class="text-sm text-slate-600">{{ __('They lose this supervisor and get the new one, in one step. Other supervisors they have stay.') }}</p>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="subordinates-move-fields" loading="moveSubordinates">{{ __('Move subordinates') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
