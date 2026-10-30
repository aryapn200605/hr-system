@props(['caption', 'head'])

{{-- The region is focusable so a keyboard can scroll a table wider than the screen.
     Below the sm breakpoint .table-stack turns each row into a card, labelled by each cell's data-label. --}}
<div {{ $attributes->class('overflow-x-auto') }} role="region" aria-label="{{ $caption }}" tabindex="0">
    <table class="table-stack w-full text-left text-sm tabular-nums [&_td]:px-2 [&_td]:py-2 [&_td]:whitespace-nowrap [&_td:first-child]:pl-0 [&_td:last-child]:pr-0 [&_th]:px-2 [&_th]:py-1.5 [&_th]:font-medium [&_th]:whitespace-nowrap [&_th:first-child]:pl-0 [&_th:last-child]:pr-0">
        <caption class="sr-only">{{ $caption }}</caption>
        <thead class="border-b border-rule text-xs text-muted">
            {{ $head }}
        </thead>
        <tbody class="divide-y divide-rule text-ink-soft">
            {{ $slot }}
        </tbody>
    </table>
</div>
