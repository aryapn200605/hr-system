<x-ui.page :title="__('Leave balance of :name', ['name' => $user->name])" :subtitle="__(':count days per period, reset on each anniversary of the anchor date. Remaining days do not carry over.', ['count' => \App\Services\LeaveBalance::ENTITLEMENT])" icon="wallet" :back="['href' => route('users.show', $user->id), 'label' => __('Back to user')]">
    @if ($isSelf)
        <x-ui.alert data-self-notice>{{ __('This is your own balance. Another HR Admin must adjust or reset it, or change its anchor date.') }}</x-ui.alert>
    @endif

    @php
        $share = $current->entitlement > 0 ? min(100, max(0, (int) round($current->remaining() / $current->entitlement * 100))) : 0;
    @endphp
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3" data-balance-stats>
        <x-ui.stat :label="__('Entitlement')" :value="__(':count days', ['count' => $current->entitlement])" icon="calendar" />
        <x-ui.stat :label="__('Used')" :value="__(':count days', ['count' => $current->used])" icon="clock" tone="amber" />
        <x-ui.stat :label="__('Remaining')" :value="__(':count days', ['count' => $current->remaining()])" icon="wallet" tone="green">
            {{-- The share of the entitlement still free; the number above is the value. --}}
            <span class="mt-1 block h-1.5 w-full overflow-hidden rounded-full bg-muted" aria-hidden="true">
                <span class="block h-full rounded-full bg-success" style="width: {{ $share }}%"></span>
            </span>
        </x-ui.stat>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Current period')" icon="calendar">
            <dl class="divide-y divide-border px-5 py-2">
                <x-ui.detail :label="__('Period')"><x-ui.date :value="$current->starts_on" /> – <x-ui.date :value="$current->ends_on" /></x-ui.detail>
                <x-ui.detail :label="__('Entitlement')">{{ __(':count days', ['count' => $current->entitlement]) }}</x-ui.detail>
                <x-ui.detail :label="__('Used')">{{ __(':count days', ['count' => $current->used]) }}</x-ui.detail>
                <x-ui.detail :label="__('Remaining')"><span data-remaining>{{ __(':count days', ['count' => $current->remaining()]) }}</span></x-ui.detail>
            </dl>

            @if ($canAdjust)
                <x-slot:footer>
                    <x-ui.button variant="secondary" size="sm" wire:click="openAdjust" loading="openAdjust"><x-ui.icon name="pencil" class="size-4" wire:loading.remove wire:target="openAdjust" />{{ __('Adjust balance') }}</x-ui.button>
                    <x-ui.button variant="secondary" size="sm" wire:click="openReset" loading="openReset"><x-ui.icon name="refresh" class="size-4" wire:loading.remove wire:target="openReset" />{{ __('Reset period') }}</x-ui.button>
                </x-slot:footer>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Anchor date')" icon="flag" tone="teal">
            <dl class="divide-y divide-border px-5 py-2">
                <x-ui.detail :label="__('Anchor date')"><x-ui.date :value="$user->leave_anchor_date" /></x-ui.detail>
                <x-ui.detail :label="__('Account created')"><x-ui.date :value="$user->created_at" /></x-ui.detail>
            </dl>
            <p class="flex items-start gap-2 px-5 pb-4 text-sm text-muted-foreground">
                <x-ui.icon name="info" class="mt-0.5 size-4 shrink-0" />
                <span>{{ __('A new period starts on every anniversary of this date. A change applies from the next period.') }}</span>
            </p>

            @if ($canAdjust)
                <x-slot:footer>
                    <x-ui.button variant="secondary" size="sm" wire:click="openAnchor" loading="openAnchor"><x-ui.icon name="calendar" class="size-4" wire:loading.remove wire:target="openAnchor" />{{ __('Change anchor date') }}</x-ui.button>
                </x-slot:footer>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Periods')" icon="list" tone="gray">
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
                <tr wire:key="period-{{ $period->id }}" @class(['bg-primary/[0.03]' => $period->is($current)])>
                    <td data-label="{{ __('Start') }}">
                        <x-ui.date :value="$period->starts_on" />
                        @if ($period->is($current))
                            <x-ui.badge tone="green" dot class="ml-1">{{ __('Current') }}</x-ui.badge>
                        @endif
                    </td>
                    <td data-label="{{ __('End') }}"><x-ui.date :value="$period->ends_on" /></td>
                    <td class="tabular-nums" data-label="{{ __('Entitlement') }}">{{ $period->entitlement }}</td>
                    <td class="tabular-nums" data-label="{{ __('Used') }}">{{ $period->used }}</td>
                    <td class="font-medium tabular-nums" data-label="{{ __('Remaining') }}">{{ $period->remaining() }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Ledger')" icon="history" tone="gray">
        <x-ui.table-skeleton target="gotoPage,nextPage,previousPage" :columns="5" :rows="5" />
        <div wire:loading.delay.remove wire:target="gotoPage,nextPage,previousPage">
            @if ($entries->isEmpty())
                <x-ui.empty-state :title="__('No entries yet')" :description="__('Every change to this balance is listed here, with who made it and why.')" icon="history" data-empty="none" />
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
                            <td data-label="{{ __('When') }}"><x-ui.datetime :value="$entry->created_at" /></td>
                            <td data-label="{{ __('Change') }}">{{ $entry->type->label() }} <span @class(['font-medium tabular-nums', 'text-green-800' => $entry->change > 0, 'text-red-800' => $entry->change < 0])>({{ $entry->change > 0 ? '+'.$entry->change : $entry->change }})</span></td>
                            <td data-label="{{ __('Period') }}"><x-ui.date :value="$entry->period->starts_on" /> – <x-ui.date :value="$entry->period->ends_on" /></td>
                            <td class="tabular-nums" data-label="{{ __('Before') }}">{{ $entry->before }}</td>
                            <td class="tabular-nums" data-label="{{ __('After') }}">{{ $entry->after }}</td>
                            <td data-label="{{ __('By') }}">{{ $entry->actor?->name ?? __('System') }}</td>
                            <td class="whitespace-normal! min-w-64 text-muted-foreground" data-label="{{ __('Reason') }}">{{ $entry->reason ?? '—' }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        <x-ui.pagination :paginator="$entries" />
    </x-ui.card>

    <x-ui.modal name="balance-adjust" icon="calendar" :title="__('Adjust balance')">
        <form id="balance-adjust-fields" class="space-y-4" wire:submit="adjust">
            <x-ui.input :label="__('Days')" id="adjust-days" type="number" step="1" min="-{{ \App\Services\LeaveBalance::MAX_ADJUSTMENT }}" max="{{ \App\Services\LeaveBalance::MAX_ADJUSTMENT }}" wire:model="days" :hint="__('A positive number adds days, a negative one removes them. The remaining days cannot go below zero.')" />
            <x-ui.textarea :label="__('Reason')" id="adjust-reason" maxlength="1000" wire:model="reason" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="balance-adjust-fields" loading="adjust">{{ __('Save adjustment') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="balance-reset" icon="alert-triangle" tone="warning" :title="__('Reset period?')">
        <form id="balance-reset-fields" class="space-y-4" wire:submit="resetBalance">
            <p>{{ __('The current period ends yesterday and a new period with :count days starts today, until the day before the next anniversary of the anchor date. The remaining days of the current period lapse.', ['count' => \App\Services\LeaveBalance::ENTITLEMENT]) }}</p>
            <x-ui.textarea :label="__('Reason')" id="reset-reason" maxlength="1000" wire:model="reason" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="danger" form="balance-reset-fields" loading="resetBalance">{{ __('Reset period') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="balance-anchor" icon="calendar" :title="__('Change anchor date')">
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
