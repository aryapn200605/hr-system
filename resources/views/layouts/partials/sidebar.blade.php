{{-- The sidebar of shadcn-admin: header with the company, grouped menu, user block with its menu. Used twice:
     the desktop sidebar (foldable to icons) and the phone drawer. Every menu entry is only a marker; each page
     checks its permission on the server itself. --}}
@php
    $canSubmit = $user->can(\App\Enums\PermissionName::RequestsSubmit->value);
    $canDecide = $user->can(\App\Enums\PermissionName::RequestsDecide->value);
    $canHistory = $user->can('viewHistory', \App\Models\LeaveRequest::class);
    $canUsers = $user->can(\App\Enums\PermissionName::UsersView->value);
    $canDepartments = $user->can(\App\Enums\PermissionName::DepartmentsManage->value);
    $canHolidays = $user->can(\App\Enums\PermissionName::HolidaysManage->value);
    $waiting = $canDecide ? app(\App\Services\Requests\Deciders::class)->waitingCount($user) : 0;
@endphp

<div class="flex h-full w-full flex-col bg-sidebar text-sidebar-foreground">
    <div class="flex flex-col gap-2 p-2">
        <a href="{{ route('dashboard') }}" class="flex h-12 w-full items-center gap-2 overflow-hidden rounded-md p-2 text-start text-sm hover:bg-sidebar-accent sidebar-collapsed:size-8 sidebar-collapsed:p-0">
            <x-ui.logo variant="icon" decorative class="size-8" />
            <span class="grid min-w-0 flex-1 text-start leading-tight sidebar-collapsed:sr-only">
                <span class="truncate font-semibold">{{ config('app.company') }}</span>
                <span class="truncate text-xs text-muted-foreground">{{ config('app.name') }}</span>
            </span>
        </a>
    </div>

    <nav aria-label="{{ $navLabel }}" class="flex min-h-0 flex-1 flex-col gap-2 overflow-auto sidebar-collapsed:overflow-hidden">
        <div class="relative flex w-full min-w-0 flex-col p-2">
            <h2 class="flex h-8 shrink-0 items-center rounded-md px-2 text-xs font-medium text-muted-foreground sidebar-collapsed:sr-only">{{ __('General') }}</h2>
            <ul class="flex w-full min-w-0 flex-col gap-1">
                <x-ui.nav-link :href="route('dashboard')" icon="home" :active="request()->routeIs('dashboard')">{{ __('Home') }}</x-ui.nav-link>

                @if ($canSubmit)
                    <x-ui.nav-link :href="route('requests.index')" icon="clipboard" :active="request()->routeIs('requests.*')">{{ __('My requests') }}</x-ui.nav-link>
                @endif

                @if ($canDecide)
                    <x-ui.nav-link :href="route('approvals.index')" icon="inbox" :active="request()->routeIs('approvals.*')" data-nav-approvals>
                        {{ __('Approvals') }}
                        @if ($waiting > 0)
                            <x-slot:badge>
                                <x-ui.badge tone="amber" class="rounded-full px-1.5 py-0" data-waiting-count>
                                    {{ $waiting }}<span class="sr-only"> {{ __('waiting for your decision') }}</span>
                                </x-ui.badge>
                            </x-slot:badge>
                        @endif
                    </x-ui.nav-link>
                @endif
            </ul>
        </div>

        @if ($canHistory)
            <div class="relative flex w-full min-w-0 flex-col p-2">
                <h2 class="flex h-8 shrink-0 items-center rounded-md px-2 text-xs font-medium text-muted-foreground sidebar-collapsed:sr-only">{{ __('Reports') }}</h2>
                <ul class="flex w-full min-w-0 flex-col gap-1">
                    <x-ui.nav-link :href="route('history.index')" icon="clock" :active="request()->routeIs('history.*')">{{ __('Request history') }}</x-ui.nav-link>
                    <x-ui.nav-link :href="route('recap.index')" icon="chart" :active="request()->routeIs('recap.*')">{{ __('Recap') }}</x-ui.nav-link>
                </ul>
            </div>
        @endif

        @if ($canUsers || $canDepartments || $canHolidays)
            <div class="relative flex w-full min-w-0 flex-col p-2">
                <h2 class="flex h-8 shrink-0 items-center rounded-md px-2 text-xs font-medium text-muted-foreground sidebar-collapsed:sr-only">{{ __('Administration') }}</h2>
                <ul class="flex w-full min-w-0 flex-col gap-1">
                    @if ($canUsers)
                        <x-ui.nav-link :href="route('users.index')" icon="users" :active="request()->routeIs('users.*')">{{ __('User Management') }}</x-ui.nav-link>
                    @endif
                    @if ($canDepartments)
                        <x-ui.nav-link :href="route('departments.index')" icon="building" :active="request()->routeIs('departments.*')">{{ __('Departments') }}</x-ui.nav-link>
                    @endif
                    @if ($canHolidays)
                        <x-ui.nav-link :href="route('holidays.index')" icon="calendar" :active="request()->routeIs('holidays.*')">{{ __('Holidays') }}</x-ui.nav-link>
                    @endif
                </ul>
            </div>
        @endif
    </nav>

    {{-- NavUser: the user block with its menu. --}}
    <div class="flex flex-col gap-2 p-2">
        <x-ui.dropdown
            :id="$menuId"
            :label="__('Account menu')"
            anchored
            :align="$menuAlign"
            width="w-60"
            trigger-class="h-12 w-full p-2 text-start data-[state=open]:bg-sidebar-accent sidebar-collapsed:size-8 sidebar-collapsed:p-0"
            class="w-full"
        >
            <x-slot:trigger>
                <x-ui.avatar :name="$user->name" class="rounded-lg" />
                <span class="grid min-w-0 flex-1 text-start leading-tight sidebar-collapsed:sr-only">
                    <span class="truncate font-semibold" title="{{ $user->name }}">{{ $user->name }}</span>
                    <span class="truncate text-xs text-muted-foreground" title="{{ $user->email }}">{{ $user->email }}</span>
                </span>
                <x-ui.icon name="chevrons-up-down" class="ms-auto size-4 shrink-0 sidebar-collapsed:hidden" />
            </x-slot:trigger>

            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm" role="none">
                <x-ui.avatar :name="$user->name" class="rounded-lg" />
                <div class="grid min-w-0 flex-1 leading-tight">
                    <span class="truncate font-semibold">{{ $user->name }}</span>
                    <span class="truncate text-xs text-muted-foreground">{{ $user->email }}</span>
                </div>
            </div>
            <div class="px-2 pb-1.5" role="none"><x-ui.role-badge :user="$user" /></div>
            <div class="-mx-1 my-1 h-px bg-border" role="none"></div>
            <x-ui.dropdown-item :href="route('profile.show')" icon="user">{{ __('My Profile') }}</x-ui.dropdown-item>
            <x-ui.dropdown-item :href="route('security.index')" icon="shield">{{ __('Security Settings') }}</x-ui.dropdown-item>
            <div class="-mx-1 my-1 h-px bg-border" role="none"></div>
            <form method="POST" action="{{ route('logout') }}" role="none">
                @csrf
                <x-ui.dropdown-item type="submit" icon="log-out" variant="destructive">{{ __('Logout') }}</x-ui.dropdown-item>
            </form>
        </x-ui.dropdown>
    </div>
</div>
