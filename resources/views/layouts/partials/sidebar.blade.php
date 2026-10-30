<div class="flex h-full flex-col">
    <div class="flex h-14 shrink-0 items-center gap-2 border-b border-slate-200 px-5">
        <span class="flex size-7 items-center justify-center rounded-lg bg-blue-700 text-xs font-semibold text-white" aria-hidden="true">HR</span>
        <span class="truncate text-sm font-semibold text-slate-900">{{ config('app.name') }}</span>
    </div>

    <nav aria-label="{{ $navLabel }}" class="flex-1 space-y-1 overflow-y-auto p-3">
        <x-ui.nav-link :href="route('dashboard')" icon="home" :active="request()->routeIs('dashboard')">Home</x-ui.nav-link>

        {{-- Only a marker. Every page behind this menu checks the permission on the server itself. --}}
        @can(\App\Enums\PermissionName::UsersView->value)
            <x-ui.nav-link :href="route('users.index')" icon="users" :active="request()->routeIs('users.*')">User Management</x-ui.nav-link>
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
