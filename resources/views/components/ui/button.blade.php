@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'block' => false,
    'loading' => null,
])

{{-- Variants after shadcn's Button: primary = default, secondary = outline, danger = destructive. The filled
     variants take the submit button's feel from the auth pages (15c): a soft shadow on hover and a pressed offset.
     loading: a Livewire action name; while it runs the button is disabled and shows a spinner. --}}
@php
    $classes = [
        'inline-flex shrink-0 items-center justify-center gap-2 rounded-md text-sm font-medium whitespace-nowrap transition-[color,background-color,border-color,box-shadow,transform] duration-150 motion-reduce:transition-none disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0',
        match ($variant) {
            'primary' => 'bg-primary text-primary-foreground shadow-xs hover:bg-primary-hover hover:shadow-md active:translate-y-px motion-reduce:active:translate-y-0',
            'secondary' => 'border border-border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground',
            'muted' => 'bg-secondary text-secondary-foreground shadow-xs hover:bg-secondary/80',
            'danger' => 'bg-destructive text-white shadow-xs hover:bg-destructive/90 hover:shadow-md active:translate-y-px motion-reduce:active:translate-y-0',
            'ghost' => 'hover:bg-accent hover:text-accent-foreground',
            'link' => 'text-primary underline underline-offset-4 hover:text-primary-hover',
        },
        match (true) {
            $variant === 'link' => '',
            $size === 'sm' => 'h-8 gap-1.5 px-3',
            $size === 'lg' => 'h-10 px-6',
            $size === 'icon' => 'size-9',
            default => 'h-9 px-4 py-2',
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
