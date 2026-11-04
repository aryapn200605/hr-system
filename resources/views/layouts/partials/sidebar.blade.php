<div class="flex h-full flex-col">
    <div class="flex h-14 shrink-0 items-center gap-2 border-b border-slate-200 px-5">
        <span class="flex size-7 items-center justify-center rounded-lg bg-blue-700 text-xs font-semibold text-white" aria-hidden="true">HR</span>
        <span class="truncate text-sm font-semibold text-slate-900">{{ config('app.name') }}</span>
    </div>

    <nav aria-label="{{ $navLabel }}" class="flex-1 space-y-1 overflow-y-auto p-3">
        <x-ui.nav-link :href="route('dashboard')" icon="home" :active="request()->routeIs('dashboard')">{{ __('Home') }}</x-ui.nav-link>

        @can(\App\Enums\PermissionName::RequestsSubmit->value)
            <x-ui.nav-link :href="route('requests.index')" icon="clipboard" :active="request()->routeIs('requests.*')">{{ __('My requests') }}</x-ui.nav-link>
        @endcan

        @can(\App\Enums\PermissionName::RequestsDecide->value)
            @php($waiting = app(\App\Services\Requests\Deciders::class)->waitingCount($user))
            <x-ui.nav-link :href="route('approvals.index')" icon="inbox" :active="request()->routeIs('approvals.*')" data-nav-approvals>
                <span class="flex-1">{{ __('Approvals') }}</span>
                @if ($waiting > 0)
                    <x-ui.badge tone="amber" data-waiting-count>
                        {{ $waiting }}<span class="sr-only"> {{ __('waiting for your decision') }}</span>
                    </x-ui.badge>
                @endif
            </x-ui.nav-link>
        @endcan

        @can('viewHistory', \App\Models\LeaveRequest::class)
            <x-ui.nav-link :href="route('history.index')" icon="clock" :active="request()->routeIs('history.*')">{{ __('Request history') }}</x-ui.nav-link>
            <x-ui.nav-link :href="route('recap.index')" icon="chart" :active="request()->routeIs('recap.*')">{{ __('Recap') }}</x-ui.nav-link>
        @endcan

        {{-- Only a marker. Every page behind this menu checks the permission on the server itself. --}}
        @can(\App\Enums\PermissionName::UsersView->value)
            <x-ui.nav-link :href="route('users.index')" icon="users" :active="request()->routeIs('users.*')">{{ __('User Management') }}</x-ui.nav-link>
        @endcan

        @can(\App\Enums\PermissionName::DepartmentsManage->value)
            <x-ui.nav-link :href="route('departments.index')" icon="building" :active="request()->routeIs('departments.*')">{{ __('Departments') }}</x-ui.nav-link>
        @endcan

        @can(\App\Enums\PermissionName::HolidaysManage->value)
            <x-ui.nav-link :href="route('holidays.index')" icon="calendar" :active="request()->routeIs('holidays.*')">{{ __('Holidays') }}</x-ui.nav-link>
        @endcan
    </nav>

    <div class="flex shrink-0 items-start gap-3 border-t border-slate-200 p-4">
        <x-ui.avatar :name="$user->name" />

        <div class="min-w-0">
            <p class="truncate text-sm font-medium text-slate-900" title="{{ $user->name }}">{{ $user->name }}</p>
            <p class="text-xs wrap-anywhere text-slate-600">{{ $user->email }}</p>
            <x-ui.role-badge :user="$user" class="mt-2" />
        </div>
    </div>
</div>
