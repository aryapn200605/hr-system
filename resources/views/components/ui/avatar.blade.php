@props(['name'])

@php
    $initials = str($name)->explode(' ')->filter()->take(2)
        ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
@endphp

<span {{ $attributes->class('flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium text-foreground') }} aria-hidden="true">{{ $initials }}</span>
