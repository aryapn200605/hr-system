@props(['href' => null, 'icon', 'active' => false, 'disabled' => false])

@php($classes = 'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium')

@if ($disabled)
    {{-- Not a link: the page does not exist yet, so nothing can be followed or focused. --}}
    <span {{ $attributes->class([$classes, 'cursor-not-allowed text-slate-600']) }} aria-disabled="true">
        <x-ui.icon :name="$icon" class="size-5 shrink-0" />
        {{ $slot }}
    </span>
@else
    <a
        href="{{ $href }}"
        @if ($active) aria-current="page" @endif
        {{ $attributes->class([$classes, $active ? 'bg-blue-50 text-blue-800' : 'text-slate-700 hover:bg-slate-100']) }}
    >
        <x-ui.icon :name="$icon" class="size-5 shrink-0" />
        {{ $slot }}
    </a>
@endif
