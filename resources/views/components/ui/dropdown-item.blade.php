@props(['href' => null, 'type' => 'button', 'disabled' => false, 'icon' => null, 'variant' => 'default'])

@php
    $classes = [
        'relative flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-start text-sm outline-offset-0 select-none',
        'text-red-700 hover:bg-red-50 focus-visible:bg-red-50' => $variant === 'destructive' && ! $disabled,
        'text-popover-foreground hover:bg-accent hover:text-accent-foreground focus-visible:bg-accent' => $variant !== 'destructive' && ! $disabled,
        'cursor-not-allowed text-muted-foreground' => $disabled,
    ];
    $iconClass = $variant === 'destructive' ? 'size-4 text-red-700' : 'size-4 text-muted-foreground';
@endphp

@if ($disabled)
    <span {{ $attributes->class($classes) }} role="menuitem" aria-disabled="true">@if ($icon)<x-ui.icon :name="$icon" :class="$iconClass" />@endif{{ $slot }}</span>
@elseif ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }} role="menuitem">@if ($icon)<x-ui.icon :name="$icon" :class="$iconClass" />@endif{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }} role="menuitem">@if ($icon)<x-ui.icon :name="$icon" :class="$iconClass" />@endif{{ $slot }}</button>
@endif
