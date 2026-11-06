<x-ui.page :title="__('Leave balance of :name', ['name' => $user->name])" :subtitle="__(':count days per period, reset on each anniversary of the anchor date. Remaining days do not carry over.', ['count' => \App\Services\LeaveBalance::ENTITLEMENT])">
    <x-slot:actions>
        <x-ui.button variant="secondary" size="sm" :href="route('users.show', $user->id)">{{ __('Back to user') }}</x-ui.button>
    </x-slot:actions>

    @if ($isSelf)
        <x-ui.alert data-self-notice>{{ __('This is your own balance. Another HR Admin must adjust or reset it, or change its anchor date.') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Current period')">
            <dl class="divide-y divide-slate-200 px-5 py-2">
                <x-ui.detail :label="__('Period')"><x-ui.date :value="$current->starts_on" /> – <x-ui.date :value="$current->ends_on" /></x-ui.detail>
                <x-ui.detail :label="__('Entitlement')">{{ __(':count days', ['count' => $current->entitlement]) }}</x-ui.detail>
                <x-ui.detail :label="__('Used')">{{ __(':count days', ['count' => $current->used]) }}</x-ui.detail>
                <x-ui.detail :label="__('Remaining')"><span data-remaining>{{ __(':count days', ['count' => $current->remaining()]) }}</span></x-ui.detail>
            </dl>

            @if ($canAdjust)
                <div class="flex flex-wrap gap-2 border-t border-slate-200 px-5 py-3.5">
                    <x-ui.button variant="secondary" size="sm" wire:click="openAdjust" loading="openAdjust">{{ __('Adjust balance') }}</x-ui.button>
                    <x-ui.button variant="secondary" size="sm" wire:click="openReset" loading="openReset">{{ __('Reset period') }}</x-ui.button>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Anchor date')">
            <dl class="divide-y divide-slate-200 px-5 py-2">
                <x-ui.detail :label="__('Anchor date')"><x-ui.date :value="$user->leave_anchor_date" /></x-ui.detail>
                <x-ui.detail :label="__('Account created')"><x-ui.date :value="$user->created_at" /></x-ui.detail>
            </dl>
            <p class="px-5 pb-4 text-sm text-slate-600">{{ __('A new period starts on every anniversary of this date. A change applies from the next period.') }}</p>

            @if ($canAdjust)
                <div class="border-t border-slate-200 px-5 py-3.5">
                    <x-ui.button variant="secondary" size="sm" wire:click="openAnchor" loading="openAnchor">{{ __('Change anchor date') }}</x-ui.button>
                </div>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Periods')">
        <x-ui.table :caption="__('Periods')">
            <x-slot:head>
                <tr>
                    <th scope="col">{{ __('Start') }}</th>
                    <th scope="col">{{ __('End') }}</th>
                    <th scope="col">{{ __('Entitlement') }}</th>
                    <th scope="col">{{ __('Used') }}</th>
                    <th scope="col">{{ __('Remaining') }}</th>
                </tr>
            </x-slot:head>

            @foreach ($periods as $period)
                <tr wire:key="period-{{ $period->id }}">
                    <td>
                        <x-ui.date :value="$period->starts_on" />
                        @if ($period->is($current))
                            <x-ui.badge tone="green" class="ml-1">{{ __('Current') }}</x-ui.badge>
                        @endif
                    </td>
                    <td><x-ui.date :value="$period->ends_on" /></td>
                    <td>{{ $period->entitlement }}</td>
                    <td>{{ $period->used }}</td>
                    <td>{{ $period->remaining() }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Ledger')">
        @if ($entries->isEmpty())
            <x-ui.empty-state :title="__('No entries yet')" />
        @else
            <x-ui.table :caption="__('Ledger')">
                <x-slot:head>
                    <tr>
                        <th scope="col">{{ __('When') }}</th>
                        <th scope="col">{{ __('Change') }}</th>
                        <th scope="col">{{ __('Period') }}</th>
                        <th scope="col">{{ __('Before') }}</th>
                        <th scope="col">{{ __('After') }}</th>
                        <th scope="col">{{ __('By') }}</th>
                        <th scope="col">{{ __('Reason') }}</th>
                    </tr>
                </x-slot:head>

                @foreach ($entries as $entry)
                    <tr wire:key="entry-{{ $entry->id }}">
                        <td><x-ui.datetime :value="$entry->created_at" /></td>
                        <td>{{ $entry->type->label() }} ({{ $entry->change > 0 ? '+'.$entry->change : $entry->change }})</td>
                        <td><x-ui.date :value="$entry->period->starts_on" /> – <x-ui.date :value="$entry->period->ends_on" /></td>
                        <td>{{ $entry->before }}</td>
                        <td>{{ $entry->after }}</td>
                        <td>{{ $entry->actor?->name ?? __('System') }}</td>
                        <td class="whitespace-normal! min-w-64">{{ $entry->reason ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif

        @if ($entries->hasPages())
            <nav aria-label="{{ __('Pagination') }}" class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3.5">
                <x-ui.button variant="secondary" size="sm" wire:click="previousPage" loading="previousPage" :disabled="$entries->onFirstPage()">{{ __('Previous') }}</x-ui.button>
                <x-ui.button variant="secondary" size="sm" wire:click="nextPage" loading="nextPage" :disabled="! $entries->hasMorePages()">{{ __('Next') }}</x-ui.button>
            </nav>
        @endif
    </x-ui.card>

    <x-ui.modal name="balance-adjust" :title="__('Adjust balance')">
        <form id="balance-adjust-fields" class="space-y-4" wire:submit="adjust">
            <x-ui.input :label="__('Days')" id="adjust-days" type="number" step="1" min="-{{ \App\Services\LeaveBalance::MAX_ADJUSTMENT }}" max="{{ \App\Services\LeaveBalance::MAX_ADJUSTMENT }}" wire:model="days" :hint="__('A positive number adds days, a negative one removes them. The remaining days cannot go below zero.')" />
            <x-ui.textarea :label="__('Reason')" id="adjust-reason" maxlength="1000" wire:model="reason" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="balance-adjust-fields" loading="adjust">{{ __('Save adjustment') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="balance-reset" :title="__('Reset period?')">
        <form id="balance-reset-fields" class="space-y-4" wire:submit="resetBalance">
            <p>{{ __('The current period ends yesterday and a new period with :count days starts today, until the day before the next anniversary of the anchor date. The remaining days of the current period lapse.', ['count' => \App\Services\LeaveBalance::ENTITLEMENT]) }}</p>
            <x-ui.textarea :label="__('Reason')" id="reset-reason" maxlength="1000" wire:model="reason" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="danger" form="balance-reset-fields" loading="resetBalance">{{ __('Reset period') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="balance-anchor" :title="__('Change anchor date')">
        <form id="balance-anchor-fields" class="space-y-4" wire:submit="saveAnchor">
            <x-ui.date-picker :label="__('Anchor date')" id="anchor-date" :min="\App\Services\LeaveBalance::EARLIEST_ANCHOR" :max="today()->toDateString()" wire:model="anchor" :hint="__('The current period keeps its dates. The next period ends on the first anniversary of the new date.')" />
            <x-ui.button variant="link" wire:click="useCreationDate" loading="useCreationDate">{{ __('Use the account creation date (:date)', ['date' => $creationDate]) }}</x-ui.button>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="balance-anchor-fields" loading="saveAnchor">{{ __('Save anchor date') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.page>
