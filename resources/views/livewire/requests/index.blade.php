<div class="space-y-6">
    <x-ui.page-header title="My requests" description="Your leave and overtime requests.">
        <x-slot:actions>
            <div class="flex flex-wrap gap-2">
                @if ($blockedReason)
                    <x-ui.button size="sm" disabled>Request leave</x-ui.button>
                    <x-ui.button size="sm" variant="secondary" disabled>Request overtime</x-ui.button>
                @else
                    <x-ui.button size="sm" :href="route('requests.leave.create')">Request leave</x-ui.button>
                    <x-ui.button size="sm" variant="secondary" :href="route('requests.overtime.create')">Request overtime</x-ui.button>
                @endif
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($blockedReason)
        <x-ui.alert tone="warning">{{ $blockedReason }}</x-ui.alert>
    @endif

    <x-ui.card flush>
        <div class="grid grid-cols-1 gap-3 border-b border-rule px-4 py-3 sm:grid-cols-3 sm:px-5">
            <x-ui.select label="Status" id="filter-status" wire:model.live="statusFilter">
                <option value="">All statuses</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <div wire:loading.class="opacity-60" wire:target="statusFilter,setPage,nextPage,previousPage">
            @if ($requests->isEmpty())
                <x-ui.empty-state title="No requests" description="Requests you submit appear here." />
            @else
                <x-ui.table caption="My requests">
                    <x-slot:head>
                        <tr>
                            <th scope="col">Type</th>
                            <th scope="col">When</th>
                            <th scope="col">Length</th>
                            <th scope="col">Status</th>
                            <th scope="col">Submitted</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($requests as $row)
                        @php($status = \App\Enums\RequestStatus::from($row->status))
                        @php($isLeave = $row->kind === 'leave')
                        @php($starts = \Carbon\CarbonImmutable::parse($row->starts))
                        @php($ends = \Carbon\CarbonImmutable::parse($row->ends))

                        <tr wire:key="{{ $row->kind }}-{{ $row->id }}">
                            <td data-primary class="font-medium text-ink">{{ $row->label }}</td>
                            <td data-label="When">
                                @if ($isLeave)
                                    {{ $starts->format('j M Y') }}{{ $starts->isSameDay($ends) ? '' : ' to '.$ends->format('j M Y') }}
                                @else
                                    {{ $starts->format('j M Y, H:i') }} to {{ $ends->format($starts->isSameDay($ends) ? 'H:i' : 'j M Y, H:i') }}
                                @endif
                            </td>
                            <td data-label="Length">{{ $row->amount }} {{ $isLeave ? 'working' : '' }} {{ str($isLeave ? 'day' : 'hour')->plural($row->amount) }}</td>
                            <td data-label="Status"><x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge></td>
                            <td data-label="Submitted"><x-ui.datetime :value="\Illuminate\Support\Carbon::parse($row->created_at)" compact /></td>
                            <td data-actions class="text-right">
                                <x-ui.button variant="secondary" size="sm" :href="route($isLeave ? 'requests.leave.show' : 'requests.overtime.show', $row->id)" :aria-label="'View '.$row->label.' request '.$row->id">View</x-ui.button>
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
