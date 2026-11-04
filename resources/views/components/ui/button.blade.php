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
        'inline-flex cursor-pointer items-center justify-center gap-2 rounded-lg font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60',
        match ($variant) {
            'primary' => 'bg-blue-700 text-white hover:bg-blue-800',
            'secondary' => 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50',
            'danger' => 'bg-red-700 text-white hover:bg-red-800',
            'ghost' => 'text-slate-700 hover:bg-slate-100',
            'link' => 'text-blue-700 underline underline-offset-2 hover:text-blue-900',
        },
        match (true) {
            $variant === 'link' => 'text-sm',
            $size === 'sm' => 'px-3 py-1.5 text-sm',
            default => 'px-4 py-2.5 text-sm',
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
