@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'block' => false,
    'loading' => null,
])

@php
    $classes = [
        'inline-flex cursor-pointer items-center justify-center gap-2 rounded-sm font-medium disabled:cursor-not-allowed disabled:opacity-60',
        match ($variant) {
            'primary' => 'bg-accent text-white hover:bg-accent-strong',
            'secondary' => 'border border-rule-strong bg-surface text-ink hover:bg-wash',
            'danger' => 'bg-tone-red text-white hover:bg-ink',
            'ghost' => 'text-ink-soft hover:bg-wash',
            'link' => 'text-accent underline underline-offset-2 hover:text-accent-strong',
        },
        match (true) {
            $variant === 'link' => 'text-sm',
            $size === 'sm' => 'px-2.5 py-1 text-sm',
            default => 'px-3.5 py-2 text-sm',
        },
        'w-full' => $block,
    ];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button
        type="{{ $type }}"
        @if ($loading) wire:loading.attr="disabled" wire:target="{{ $loading }}" @endif
        {{ $attributes->class($classes) }}
    >
        @if ($loading)
            <span wire:loading.inline-flex wire:target="{{ $loading }}"><x-ui.spinner /></span>
        @endif
        {{ $slot }}
    </button>
@endif
