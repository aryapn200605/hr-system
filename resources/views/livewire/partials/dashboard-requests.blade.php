{{-- The request and leave numbers of App\Services\Reports\DashboardSummary. A null number is one this user is not entitled to. --}}
@php($link = 'font-medium text-blue-700 underline underline-offset-2 hover:text-blue-900')

@if ($summary['balance'] !== null || $summary['waitingForMe'] !== null || $summary['pendingTotal'] !== null || $summary['withoutSupervisor'] !== null)
    <section aria-labelledby="requests-summary-heading" class="space-y-4" data-requests-summary>
        <h2 id="requests-summary-heading" class="text-base font-semibold text-slate-900">{{ __('Requests and leave') }}</h2>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @if ($summary['balance'] !== null)
                @php($balance = $summary['balance'])
                <x-ui.stat :label="__('Annual leave available')" :value="__(':count days', ['count' => $balance->available()])" data-balance>
                    <p data-balance-period>{{ __('Current period') }}: <x-ui.date :value="$balance->startsOn" /> – <x-ui.date :value="$balance->endsOn" /></p>
                    @if ($balance->pending > 0)
                        <p data-balance-pending>{{ __(':count days are held by pending requests.', ['count' => $balance->pending]) }}</p>
                    @endif
                </x-ui.stat>
            @endif

            @if ($summary['ownPending'] !== null)
                <x-ui.stat :label="__('My pending requests')" :value="$summary['ownPending']" data-own-pending>
                    @if ($summary['ownPending'] > 0)
                        <a href="{{ route('history.index', ['status' => \App\Enums\RequestStatus::Pending->value]) }}" class="{{ $link }}" data-own-pending-link>{{ __('View in request history') }}</a>
                    @else
                        <p>{{ __('None of your requests is waiting for a decision.') }}</p>
                    @endif
                </x-ui.stat>
            @endif

            @if ($summary['waitingForMe'] !== null)
                <x-ui.stat :label="__('Waiting for my decision')" :value="$summary['waitingForMe']" data-waiting>
                    @if ($summary['waitingForMe'] > 0)
                        <a href="{{ route('approvals.index') }}" class="{{ $link }}" data-waiting-link>{{ __('Open approvals') }}</a>
                    @else
                        <p>{{ __('Nothing is waiting for your decision.') }}</p>
                    @endif
                </x-ui.stat>
            @endif

            @if ($summary['pendingTotal'] !== null)
                <x-ui.stat :label="__('Pending requests in the organization')" :value="$summary['pendingTotal']" data-pending-total>
                    @if ($summary['pendingTotal'] > 0)
                        <a href="{{ route('history.index', ['status' => \App\Enums\RequestStatus::Pending->value]) }}" class="{{ $link }}" data-pending-total-link>{{ __('View in request history') }}</a>
                    @else
                        <p>{{ __('No request is waiting for a decision.') }}</p>
                    @endif
                </x-ui.stat>
            @endif

            @if ($summary['withoutSupervisor'] !== null)
                <x-ui.stat :label="__('Employees without a supervisor')" :value="$summary['withoutSupervisor']" data-without-supervisor>
                    @if ($summary['withoutSupervisor'] > 0)
                        <a href="{{ route('users.index', ['supervisor' => 'none']) }}" class="{{ $link }}" data-without-supervisor-link>{{ __('Show them in User Management') }}</a>
                    @else
                        <p>{{ __('Every Employee and HR Admin has an active supervisor.') }}</p>
                    @endif
                </x-ui.stat>
            @endif
        </div>

        @if ($summary['balance'] !== null)
            <x-ui.card :title="__('My last decided request')" data-last-decided>
                @php($last = $summary['lastDecided'])

                @if ($last === null)
                    <x-ui.empty-state :title="__('No decision yet')" :description="__('When one of your requests is approved or rejected, it is shown here.')" />
                @else
                    <dl class="divide-y divide-slate-200 px-5 py-2">
                        <x-ui.detail :label="__('Type')">{{ $last->type === 'leave' ? __($last->categoryName) : __('Overtime') }}</x-ui.detail>
                        <x-ui.detail :label="__('Dates')">
                            @if ($last->type === 'leave')
                                <x-ui.date :value="$last->starts" /> – <x-ui.date :value="$last->ends" />
                            @else
                                <x-ui.datetime :value="$last->starts" /> – <x-ui.datetime :value="$last->ends" />
                            @endif
                        </x-ui.detail>
                        <x-ui.detail :label="__('Status')"><x-ui.badge :tone="$last->status->tone()" data-last-status>{{ $last->status->label() }}</x-ui.badge></x-ui.detail>
                        <x-ui.detail :label="__('Decided')"><x-ui.datetime :value="$last->decidedAt" /></x-ui.detail>
                    </dl>
                    <div class="border-t border-slate-200 px-5 py-3 text-sm">
                        <a href="{{ route('requests.show', ['type' => $last->type, 'id' => $last->id]) }}" class="{{ $link }}" data-last-decided-link>{{ __('Open the request') }}</a>
                    </div>
                @endif
            </x-ui.card>
        @endif
    </section>
@endif
