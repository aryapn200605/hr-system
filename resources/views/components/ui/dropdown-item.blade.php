@props(['href' => null, 'type' => 'button', 'disabled' => false])

@php($classes = 'flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-sm')

@if ($disabled)
    <span {{ $attributes->class([$classes, 'cursor-not-allowed text-muted']) }} role="menuitem" aria-disabled="true">{{ $slot }}</span>
@elseif ($href)
    <a href="{{ $href }}" {{ $attributes->class([$classes, 'text-ink hover:bg-wash']) }} role="menuitem">{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class([$classes, 'cursor-pointer text-ink hover:bg-wash']) }} role="menuitem">{{ $slot }}</button>
@endif
