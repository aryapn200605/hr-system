<div class="flex h-full flex-col">
    <div class="flex h-12 shrink-0 items-center border-b border-rule px-4">
        <span class="truncate text-sm font-semibold text-ink">{{ config('app.name') }}</span>
    </div>

    <nav aria-label="{{ $navLabel }}" class="flex-1 overflow-y-auto py-3">
        <x-ui.nav-link :href="route('dashboard')" icon="home" :active="request()->routeIs('dashboard')">Home</x-ui.nav-link>

        {{-- Only a marker. Every page behind this menu checks the permission on the server itself. --}}
        @can(\App\Enums\PermissionName::UsersView->value)
            <x-ui.nav-link :href="route('users.index')" icon="users" :active="request()->routeIs('users.*')">User Management</x-ui.nav-link>
        @endcan
    </nav>

    <div class="shrink-0 border-t border-rule px-4 py-3">
        <p class="truncate text-sm font-medium text-ink" title="{{ $user->name }}">{{ $user->name }}</p>
        <p class="text-xs wrap-anywhere text-muted">{{ $user->email }}</p>
        <x-ui.role-badge :user="$user" class="mt-1.5" />
    </div>
</div>
