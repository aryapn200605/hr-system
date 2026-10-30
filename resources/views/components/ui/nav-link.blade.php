@props(['href' => null, 'icon', 'active' => false, 'disabled' => false])

@php($classes = 'mx-2 flex items-center gap-2.5 rounded-sm px-2.5 py-2 text-sm transition-colors duration-100')

@if ($disabled)
    {{-- Not a link: the page does not exist yet, so nothing can be followed or focused. --}}
    <span {{ $attributes->class([$classes, 'cursor-not-allowed text-muted']) }} aria-disabled="true">
        <x-ui.icon :name="$icon" class="size-4 shrink-0" />
        {{ $slot }}
    </span>
@else
    <a
        href="{{ $href }}"
        @if ($active) aria-current="page" @endif
        {{ $attributes->class([$classes, $active ? 'bg-accent-wash font-semibold text-accent' : 'text-ink-soft hover:bg-wash hover:text-ink']) }}
    >
        <x-ui.icon :name="$icon" class="size-4 shrink-0" />
        {{ $slot }}
    </a>
@endif
