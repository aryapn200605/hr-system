<div class="space-y-6">
    <x-ui.page-header :title="$user->name" description="Department, join date and supervisors.">
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" :href="route('users.show', $user->id)">Back to details</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
        <x-ui.card title="Employment">
            @can('update', $user)
                <form wire:submit="saveDetails" class="space-y-4 py-2">
                    <x-ui.select label="Department" id="employment-department" wire:model="departmentId">
                        <option value="">No department</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}{{ $department->is_active ? '' : ' (inactive)' }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input label="Join date" id="employment-joined" type="date" wire:model="joinedAt" />

                    <x-ui.button type="submit" loading="saveDetails">Save</x-ui.button>
                </form>
            @else
                <dl class="divide-y divide-rule">
                    <x-ui.detail label="Department">{{ $user->department?->name ?? 'None' }}</x-ui.detail>
                    <x-ui.detail label="Join date">{{ $user->joined_at?->format('j M Y') ?? 'Not set' }}</x-ui.detail>
                </dl>
            @endcan
        </x-ui.card>

        <x-ui.card title="Supervisors" flush>
            @if ($supervisors->isEmpty())
                <x-ui.empty-state title="No supervisor" description="This user cannot submit requests until they have one." />
            @else
                <ul class="divide-y divide-rule">
                    @foreach ($supervisors as $supervisor)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 sm:px-5" wire:key="supervisor-{{ $supervisor->id }}">
                            <span class="flex min-w-0 flex-wrap items-center gap-2 text-sm wrap-anywhere text-ink">
                                {{ $supervisor->name }}
                                @unless ($activeSupervisorIds->contains($supervisor->id))
                                    <x-ui.badge tone="amber">Not active</x-ui.badge>
                                @endunless
                            </span>

                            @can('manageSupervisors', \App\Models\User::class)
                                <x-ui.button variant="secondary" size="sm" wire:click="removeSupervisor({{ $supervisor->id }})" :aria-label="'Remove '.$supervisor->name">Remove</x-ui.button>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @endif

            @can('manageSupervisors', \App\Models\User::class)
                <x-slot:footer>
                    <form wire:submit="addSupervisor" class="flex w-full flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="min-w-0 flex-1">
                            <x-ui.select label="Add a supervisor" id="employment-supervisor" wire:model="supervisorId">
                                <option value="">Choose an active Approver</option>
                                @foreach ($candidates as $candidate)
                                    <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        <x-ui.button type="submit" variant="secondary" loading="addSupervisor">Add</x-ui.button>
                    </form>
                </x-slot:footer>
            @endcan
        </x-ui.card>
    </div>
</div>
