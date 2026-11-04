@props(['name'])

@php
    $initials = str($name)->explode(' ')->filter()->take(2)
        ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
@endphp

<span {{ $attributes->class('flex size-9 shrink-0 items-center justify-center rounded-full bg-slate-200 text-sm font-medium text-slate-800') }} aria-hidden="true">{{ $initials }}</span>
