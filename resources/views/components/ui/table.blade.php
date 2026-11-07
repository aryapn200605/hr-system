@props(['caption', 'head', 'cards' => true])

{{-- shadcn's Table. The region is focusable so a keyboard can scroll a table wider than the screen.
     Phase 15e, cards: below 1024 px each row becomes a card (.table-cards in app.css). Every cell carries
     data-label with its column's header text, shown before the value, so a card holds everything the row holds.
     A cell without a label spans the card: data-card="title" (the row's name, at the top), data-card="menu"
     (a row menu, top right) and data-card="actions" (buttons, at the bottom); a th in a body row is a heading.
     Each cell's content is wrapped in one <div data-cell> here, so a value made of several pieces (two dates and
     a dash) stays one block beside its label. Only the tags are touched; the content is the rendered slot as is. --}}
@php
    $body = (string) $slot;

    if ($cards) {
        $body = preg_replace('#(<td\b[^>]*>)(.*?)(</td>)#s', '$1<div data-cell>$2</div>$3', $body) ?? $body;
    }
@endphp
<div {{ $attributes->class('relative w-full overflow-x-auto') }} role="region" aria-label="{{ $caption }}" tabindex="0">
    <table @class([
        'w-full caption-bottom text-left text-sm [&_td]:px-5 [&_td]:py-3 [&_td]:align-middle [&_td]:whitespace-nowrap [&_th]:h-10 [&_th]:px-5 [&_th]:text-start [&_th]:align-middle [&_th]:font-medium [&_th]:whitespace-nowrap',
        'table-cards' => $cards,
    ])>
        <caption class="sr-only">{{ $caption }}</caption>
        <thead class="border-b border-border bg-muted/50 text-foreground">
            {{ $head }}
        </thead>
        <tbody class="divide-y divide-border text-foreground [&_tr]:transition-colors [&_tr:hover]:bg-muted/50 motion-reduce:[&_tr]:transition-none">
            {!! $body !!}
        </tbody>
    </table>
</div>
