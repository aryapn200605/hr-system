<div class="space-y-6">
    <x-ui.page-header :title="$user->name" description="Annual leave, by period.">
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" :href="route('users.show', $user->id)">Back to details</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    <x-ui.card title="Current period">
        @if ($current)
            <dl class="divide-y divide-rule">
                <x-ui.detail label="Period">{{ $current->start_date->format('j M Y') }} to {{ $current->end_date->format('j M Y') }}</x-ui.detail>
                <x-ui.detail label="Entitlement">{{ $current->entitlement }} days</x-ui.detail>
                <x-ui.detail label="Used">{{ $current->used }} days</x-ui.detail>
                <x-ui.detail label="Remaining">{{ $current->remaining() }} days</x-ui.detail>
                <x-ui.detail label="Reset date">Every {{ $user->leave_anchor_date->format('j F') }}, from {{ $user->leave_anchor_date->format('j M Y') }}</x-ui.detail>
            </dl>
        @else
            <p class="py-2 text-sm text-muted">No open period yet. The daily check opens the first one.</p>
        @endif

        @can(\App\Enums\PermissionName::LeaveBalanceAdjust->value)
            <x-slot:footer>
                <x-ui.button variant="secondary" size="sm" wire:click="openAdjust">Adjust balance</x-ui.button>
                <x-ui.button variant="secondary" size="sm" wire:click="openAnchor">Change reset date</x-ui.button>
                <x-ui.button variant="secondary" size="sm" wire:click="openReset">Reset now</x-ui.button>
            </x-slot:footer>
        @endcan
    </x-ui.card>

    <x-ui.card title="Periods" flush>
        @if ($periods->isEmpty())
            <x-ui.empty-state title="No periods yet" />
        @else
            <x-ui.table caption="Leave periods">
                <x-slot:head>
                    <tr>
                        <th scope="col">Start</th>
                        <th scope="col">End</th>
                        <th scope="col">Entitlement</th>
                        <th scope="col">Used</th>
                        <th scope="col">Status</th>
                    </tr>
                </x-slot:head>

                @foreach ($periods as $period)
                    <tr wire:key="period-{{ $period->id }}">
                        <td data-primary>{{ $period->start_date->format('j M Y') }}</td>
                        <td data-label="End">{{ $period->end_date->format('j M Y') }}</td>
                        <td data-label="Entitlement">{{ $period->entitlement }}</td>
                        <td data-label="Used">{{ $period->used }}</td>
                        <td data-label="Status">
                            <x-ui.badge :tone="$period->closed_at ? 'gray' : 'green'">{{ $period->closed_at ? 'Closed' : 'Open' }}</x-ui.badge>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card title="Changes" flush>
        @if ($entries->isEmpty())
            <x-ui.empty-state title="No changes yet" />
        @else
            <x-ui.table caption="Balance changes" stack="wide">
                <x-slot:head>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Change</th>
                        <th scope="col">By</th>
                        <th scope="col">Entitlement</th>
                        <th scope="col">Used</th>
                        <th scope="col">Reason</th>
                    </tr>
                </x-slot:head>

                @foreach ($entries as $entry)
                    <tr wire:key="entry-{{ $entry->id }}">
                        <td data-primary><x-ui.datetime :value="$entry->created_at" /></td>
                        <td data-label="Change">{{ $entry->type->label() }}</td>
                        <td data-label="By">{{ $entry->actor?->name ?? 'System' }}</td>
                        <td data-label="Entitlement">{{ $entry->entitlement_before ?? '–' }} → {{ $entry->entitlement_after }}</td>
                        <td data-label="Used">{{ $entry->used_before ?? '–' }} → {{ $entry->used_after }}</td>
                        <td data-label="Reason" data-span class="wrap-anywhere">{{ $entry->reason }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.modal name="leave-adjust" title="Adjust balance">
        <form id="leave-adjust-fields" class="space-y-4" wire:submit="adjust">
            <x-ui.input label="Change to entitlement (days)" id="leave-entitlement" type="number" wire:model="entitlementChange" hint="Use a minus sign to take days away." />
            <x-ui.input label="Change to used days" id="leave-used" type="number" wire:model="usedChange" />
            <x-ui.input label="Reason" id="leave-reason" maxlength="500" autocomplete="off" wire:model="reason" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="leave-adjust-fields" loading="adjust">Save adjustment</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="leave-anchor" title="Change reset date">
        <form id="leave-anchor-fields" class="space-y-4" wire:submit="saveAnchor">
            <x-ui.input label="Reset date" id="leave-anchor-date" type="date" wire:model="anchorDate" hint="The balance resets every year on this day and month. The current period keeps its days and ends the day before the next reset." />

            <div class="flex flex-wrap gap-2">
                <x-ui.button variant="secondary" size="sm" x-on:click="$wire.anchorDate = '{{ $user->created_at->toDateString() }}'">Use account creation date</x-ui.button>
                @if ($user->joined_at)
                    <x-ui.button variant="secondary" size="sm" x-on:click="$wire.anchorDate = '{{ $user->joined_at->toDateString() }}'">Use join date</x-ui.button>
                @endif
            </div>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="leave-anchor-fields" loading="saveAnchor">Save reset date</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="leave-reset" title="Reset now?">
        <p>The current period ends yesterday and its unused days expire. A new period of {{ \App\Support\LeaveBalances::ANNUAL_ENTITLEMENT }} days starts today and runs to the next reset date.</p>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button variant="danger" wire:click="resetBalance" loading="resetBalance">Reset balance</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
