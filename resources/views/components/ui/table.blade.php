@props(['caption', 'head', 'stack' => 'narrow'])

{{-- Rows become cards from the room the table has, not the width of the screen: the sidebar can leave a
     table short of space on a laptop. stack="wide" is for tables with many columns. Each cell labels
     itself with data-label. The region is focusable, so a keyboard can reach anything that scrolls. --}}
<div {{ $attributes->class('@container overflow-x-auto') }} role="region" aria-label="{{ $caption }}" tabindex="0">
    <table data-stack="{{ $stack }}" class="table-stack w-full text-left text-sm tabular-nums">
        <caption class="sr-only">{{ $caption }}</caption>
        <thead class="border-b border-rule bg-paper text-xs text-muted">
            {{ $head }}
        </thead>
        <tbody class="divide-y divide-rule text-ink-soft">
            {{ $slot }}
        </tbody>
    </table>
</div>
