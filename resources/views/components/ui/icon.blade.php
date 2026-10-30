@props(['name'])

@php
    $path = match ($name) {
        'home' => 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-9.5Z',
        'users' => 'M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm13 9v-1a4 4 0 0 0-3-3.87M16 3.13a3.5 3.5 0 0 1 0 6.75',
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'close' => 'M6 6l12 12M18 6 6 18',
        'chevron-down' => 'm6 9 6 6 6-6',
        'chevron-right' => 'm9 6 6 6-6 6',
        'dots' => 'M4.5 12h1M11.5 12h1M18.5 12h1',
    };
@endphp

<svg {{ $attributes }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="{{ $path }}" />
</svg>
