@props(['name'])

@php
    $initials = str($name)->explode(' ')->filter()->take(2)
        ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
@endphp

<span data-initials="{{ $initials }}" {{ $attributes->class('flex size-8 shrink-0 items-center justify-center rounded-sm bg-wash text-xs font-semibold text-ink-soft before:content-[attr(data-initials)]') }} aria-hidden="true"></span>
