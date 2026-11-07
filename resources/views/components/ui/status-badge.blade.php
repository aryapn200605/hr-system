@props(['status', 'dot' => false])

<x-ui.badge :tone="$status->tone()" :dot="$dot" {{ $attributes }}>{{ $status->label() }}</x-ui.badge>
