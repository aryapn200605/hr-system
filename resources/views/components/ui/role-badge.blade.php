@props(['user'])

@php($role = \App\Enums\RoleName::tryFrom((string) $user->roleName()))

<x-ui.badge :tone="$role?->tone() ?? 'gray'" data-role-badge {{ $attributes }}>{{ $role?->label() ?? $user->roleName() ?? __('No role') }}</x-ui.badge>
