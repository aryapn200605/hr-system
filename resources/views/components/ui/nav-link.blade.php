@props(['href' => null, 'icon', 'active' => false, 'disabled' => false])

@php($classes = 'flex items-center gap-2.5 border-l-2 py-1.5 pr-4 pl-3.5 text-sm')

@if ($disabled)
    {{-- Not a link: the page does not exist yet, so nothing can be followed or focused. --}}
    <span {{ $attributes->class([$classes, 'cursor-not-allowed border-transparent text-muted']) }} aria-disabled="true">
        <x-ui.icon :name="$icon" class="size-4 shrink-0" />
        {{ $slot }}
    </span>
@else
    <a
        href="{{ $href }}"
        @if ($active) aria-current="page" @endif
        {{ $attributes->class([$classes, $active ? 'border-accent font-semibold text-ink' : 'border-transparent text-ink-soft hover:bg-wash hover:text-ink']) }}
    >
        <x-ui.icon :name="$icon" class="size-4 shrink-0" />
        {{ $slot }}
    </a>
@endif
