{{-- The request and leave numbers of App\Services\Reports\DashboardSummary. A null number is one this user is not entitled to. --}}
@php($link = 'font-medium text-primary underline-offset-4 hover:underline')

@if ($summary['balance'] !== null || $summary['waitingForMe'] !== null || $summary['pendingTotal'] !== null || $summary['withoutSupervisor'] !== null)
    <section aria-labelledby="requests-summary-heading" class="space-y-4" data-requests-summary>
        <h2 id="requests-summary-heading" class="text-lg font-semibold tracking-tight">{{ __('Requests and leave') }}</h2>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @if ($summary['balance'] !== null)
                @php($balance = $summary['balance'])
                <x-ui.stat :label="__('Annual leave available')" icon="wallet" tone="green" :value="__(':count days', ['count' => $balance->available()])" data-balance>
                    <p data-balance-period>{{ __('Current period') }}: <x-ui.date :value="$balance->startsOn" /> – <x-ui.date :value="$balance->endsOn" /></p>
                    @if ($balance->pending > 0)
                        <p data-balance-pending>{{ __(':count days are held by pending requests.', ['count' => $balance->pending]) }}</p>
                    @endif
                </x-ui.stat>
            @endif

            @if ($summary['ownPending'] !== null)
                <x-ui.stat :label="__('My pending requests')" icon="clipboard" tone="amber" :value="$summary['ownPending']" data-own-pending>
                    @if ($summary['ownPending'] > 0)
                        <a href="{{ route('history.index', ['status' => \App\Enums\RequestStatus::Pending->value]) }}" class="{{ $link }}" data-own-pending-link>{{ __('View in request history') }}</a>
                    @else
                        <p>{{ __('None of your requests is waiting for a decision.') }}</p>
                    @endif
                </x-ui.stat>
            @endif

            @if ($summary['waitingForMe'] !== null)
                <x-ui.stat :label="__('Waiting for my decision')" icon="inbox" :value="$summary['waitingForMe']" data-waiting>
                    @if ($summary['waitingForMe'] > 0)
                        <a href="{{ route('approvals.index') }}" class="{{ $link }}" data-waiting-link>{{ __('Open approvals') }}</a>
                    @else
                        <p>{{ __('Nothing is waiting for your decision.') }}</p>
                    @endif
                </x-ui.stat>
            @endif

            @if ($summary['pendingTotal'] !== null)
                <x-ui.stat :label="__('Pending requests in the organization')" icon="hourglass" tone="amber" :value="$summary['pendingTotal']" data-pending-total>
                    @if ($summary['pendingTotal'] > 0)
                        <a href="{{ route('history.index', ['status' => \App\Enums\RequestStatus::Pending->value]) }}" class="{{ $link }}" data-pending-total-link>{{ __('View in request history') }}</a>
                    @else
                        <p>{{ __('No request is waiting for a decision.') }}</p>
                    @endif
                </x-ui.stat>
            @endif

            @if ($summary['withoutSupervisor'] !== null)
                <x-ui.stat :label="__('Employees without a supervisor')" icon="users" :tone="$summary['withoutSupervisor'] > 0 ? 'red' : 'gray'" :value="$summary['withoutSupervisor']" data-without-supervisor>
                    @if ($summary['withoutSupervisor'] > 0)
                        <a href="{{ route('users.index', ['supervisor' => 'none']) }}" class="{{ $link }}" data-without-supervisor-link>{{ __('Show them in User Management') }}</a>
                    @else
                        <p>{{ __('Every Employee and HR Admin has an active supervisor.') }}</p>
                    @endif
                </x-ui.stat>
            @endif
        </div>
    </section>
@endif
