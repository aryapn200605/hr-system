<div class="space-y-6">
    <x-ui.page-header title="Approvals" description="Requests waiting for your decision, oldest first." />

    <nav aria-label="Approvals" class="flex flex-wrap gap-x-5 border-b border-rule">
        @foreach (['pending' => 'Waiting ('.$pendingCount.')', 'decided' => 'Decided by me'] as $value => $label)
            <button
                type="button"
                wire:click="$set('tab', '{{ $value }}')"
                @if ($tab === $value) aria-current="page" @endif
                @class([
                    '-mb-px cursor-pointer border-b-2 py-2 text-sm transition-colors duration-100',
                    'border-accent font-semibold text-ink' => $tab === $value,
                    'border-transparent text-ink-soft hover:border-rule-strong hover:text-ink' => $tab !== $value,
                ])
            >{{ $label }}</button>
        @endforeach
    </nav>

    <x-ui.card flush>
        <div class="grid grid-cols-1 gap-3 border-b border-rule px-4 py-3 sm:grid-cols-3 sm:px-5">
            <x-ui.select label="Type" id="filter-kind" wire:model.live="kindFilter">
                <option value="">All types</option>
                <option value="leave">Leave</option>
                <option value="overtime">Overtime</option>
            </x-ui.select>
        </div>

        <div wire:loading.class="opacity-60" wire:target="tab,kindFilter,setPage,nextPage,previousPage">
            @if ($requests->isEmpty())
                <x-ui.empty-state :title="$tab === 'pending' ? 'Nothing is waiting' : 'No decisions yet'" />
            @else
                <x-ui.table caption="Approvals">
                    <x-slot:head>
                        <tr>
                            <th scope="col">Requester</th>
                            <th scope="col">Type</th>
                            <th scope="col">When</th>
                            <th scope="col">Length</th>
                            <th scope="col">{{ $tab === 'pending' ? 'Submitted' : 'Decision' }}</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($requests as $row)
                        @php($isLeave = $row->kind === 'leave')
                        @php($starts = \Carbon\CarbonImmutable::parse($row->starts))
                        @php($ends = \Carbon\CarbonImmutable::parse($row->ends))
                        @php($status = \App\Enums\RequestStatus::from($row->status))

                        <tr wire:key="{{ $row->kind }}-{{ $row->id }}">
                            <td data-primary class="font-medium wrap-anywhere text-ink">{{ $row->requester }}</td>
                            <td data-label="Type">{{ $row->label }}</td>
                            <td data-label="When">
                                @if ($isLeave)
                                    {{ $starts->format('j M Y') }}{{ $starts->isSameDay($ends) ? '' : ' to '.$ends->format('j M Y') }}
                                @else
                                    {{ $starts->format('j M Y, H:i') }} to {{ $ends->format($starts->isSameDay($ends) ? 'H:i' : 'j M Y, H:i') }}
                                @endif
                            </td>
                            <td data-label="Length">{{ $row->amount }} {{ $isLeave ? 'working' : '' }} {{ str($isLeave ? 'day' : 'hour')->plural($row->amount) }}</td>
                            <td data-label="{{ $tab === 'pending' ? 'Submitted' : 'Decision' }}">
                                @if ($tab === 'pending')
                                    <x-ui.datetime :value="\Illuminate\Support\Carbon::parse($row->created_at)" compact />
                                @else
                                    <x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge>
                                @endif
                            </td>
                            <td data-actions class="text-right">
                                <x-ui.button variant="secondary" size="sm" :href="route('approvals.'.$row->kind, $row->id)" :aria-label="'Open '.$row->label.' request of '.$row->requester">Open</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($requests->total() > 0)
            <x-ui.pagination :paginator="$requests" />
        @endif
    </x-ui.card>
</div>
