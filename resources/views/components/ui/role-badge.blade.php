@props(['user'])

@php($role = $user->roleName())

<x-ui.badge :tone="\App\Enums\RoleName::tryFrom((string) $role)?->tone() ?? 'gray'" data-role-badge {{ $attributes }}>{{ $role ?? 'No role' }}</x-ui.badge>
