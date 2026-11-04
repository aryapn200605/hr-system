<div
    class="space-y-6"
    @if ($request->isPending()) wire:poll.60s="heartbeat" @endif
    x-on:pagehide.window="navigator.sendBeacon(@js(route('approvals.release', [$kind, $request->id])), new URLSearchParams({ _token: @js(csrf_token()) }))"
>
    <x-ui.page-header :title="$title">
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" :href="route('approvals.index')">Back to approvals</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($notice !== '')
        <x-ui.alert tone="warning" data-notice>{{ $notice }}</x-ui.alert>
    @endif

    @if ($holder)
        <x-ui.alert tone="info" data-read-only>{{ $holder->user->name }} has this request open, so it is read only for you. It frees up when they leave.</x-ui.alert>
    @endif

    <x-ui.card>
        <dl class="divide-y divide-rule">
            <x-ui.detail label="Requester">{{ $request->user->name }}</x-ui.detail>

            @if ($kind === 'leave')
                <x-ui.detail label="Category">{{ $request->category->name }}</x-ui.detail>
                <x-ui.detail label="First day">{{ $request->start_date->format('j M Y') }}</x-ui.detail>
                <x-ui.detail label="Last day">{{ $request->end_date->format('j M Y') }}</x-ui.detail>
                <x-ui.detail label="Working days">{{ $request->working_days }}</x-ui.detail>
            @else
                <x-ui.detail label="Start">{{ $request->start_at->format('j M Y, H:i') }} WIB</x-ui.detail>
                <x-ui.detail label="End">{{ $request->end_at->format('j M Y, H:i') }} WIB</x-ui.detail>
                <x-ui.detail label="Hours">{{ $request->hours }}</x-ui.detail>
                @foreach ($hoursByDay as $day => $hours)
                    <x-ui.detail :label="'Overtime on '.$day">{{ $hours }} {{ str('hour')->plural($hours) }} in total, pending and approved</x-ui.detail>
                @endforeach
            @endif

            <x-ui.detail label="Status"><x-ui.badge :tone="$request->status->tone()">{{ $request->status->label() }}</x-ui.badge></x-ui.detail>
            <x-ui.detail label="Reason">{{ $request->reason }}</x-ui.detail>
            <x-ui.detail label="Evidence">
                @if ($request->hasEvidence())
                    <x-ui.button variant="link" :href="$evidenceUrl">Download evidence</x-ui.button>
                @else
                    None
                @endif
            </x-ui.detail>
            <x-ui.detail label="Submitted"><x-ui.datetime :value="$request->created_at" /></x-ui.detail>

            @if ($request->decided_at)
                <x-ui.detail :label="$request->status->label().' by'">{{ $request->decider?->name }}</x-ui.detail>
                <x-ui.detail label="Decided"><x-ui.datetime :value="$request->decided_at" /></x-ui.detail>
            @endif
            @if ($request->rejection_reason)
                <x-ui.detail label="Reason for rejection">{{ $request->rejection_reason }}</x-ui.detail>
            @endif
            @if ($request->decision_note)
                <x-ui.detail label="Note">{{ $request->decision_note }}</x-ui.detail>
            @endif
        </dl>

        @if ($mayDecide)
            <x-slot:footer>
                <x-ui.button size="sm" x-on:click="$dispatch('open-modal', { name: 'request-approve' })">Approve</x-ui.button>
                <x-ui.button variant="danger" size="sm" x-on:click="$dispatch('open-modal', { name: 'request-reject' })">Reject</x-ui.button>
            </x-slot:footer>
        @endif
    </x-ui.card>

    @if ($parts !== [])
        <x-ui.card title="Balance by period" flush>
            <x-ui.table caption="Balance by period">
                <x-slot:head>
                    <tr>
                        <th scope="col">Period</th>
                        <th scope="col">This request</th>
                        <th scope="col">Left before it</th>
                    </tr>
                </x-slot:head>

                @foreach ($parts as $part)
                    <tr wire:key="part-{{ $part['window']->start->toDateString() }}">
                        <td data-primary>{{ $part['window']->label() }}{{ $part['window']->projected ? ' (not open yet)' : '' }}</td>
                        <td data-label="This request">{{ count($part['allocations']) }} {{ str('day')->plural(count($part['allocations'])) }}</td>
                        <td data-label="Left before it">{{ $part['window']->entitlement - $part['window']->used }} {{ str('day')->plural($part['window']->entitlement - $part['window']->used) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    {{-- Both stay in the page when the buttons go: removing an open dialog would leave its focus trap holding every click. --}}
    <x-ui.modal name="request-approve" title="Approve this request?">
        <form id="request-approve-fields" wire:submit="approve">
            <x-ui.input label="Note (optional)" id="decision-note" maxlength="1000" autocomplete="off" wire:model="note" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Back</x-ui.button>
            <x-ui.button type="submit" form="request-approve-fields" loading="approve">Approve request</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="request-reject" title="Reject this request?">
        <form id="request-reject-fields" wire:submit="reject">
            <x-ui.textarea label="Reason" name="reason" id="decision-reason" maxlength="1000" hint="The requester sees this. At least 5 characters." wire:model="reason" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Back</x-ui.button>
            <x-ui.button variant="danger" type="submit" form="request-reject-fields" loading="reject">Reject request</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
