@props(['href' => null, 'type' => 'button', 'disabled' => false])

@php($classes = 'flex w-full items-center justify-between gap-2 px-3.5 py-2 text-left text-sm')

@if ($disabled)
    <span {{ $attributes->class([$classes, 'cursor-not-allowed text-slate-600']) }} role="menuitem" aria-disabled="true">{{ $slot }}</span>
@elseif ($href)
    <a href="{{ $href }}" {{ $attributes->class([$classes, 'text-slate-800 hover:bg-slate-100']) }} role="menuitem">{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class([$classes, 'cursor-pointer text-slate-800 hover:bg-slate-100']) }} role="menuitem">{{ $slot }}</button>
@endif
