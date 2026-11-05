@props(['caption', 'head'])

{{-- shadcn's Table. The region is focusable so a keyboard can scroll a table wider than the screen. --}}
<div {{ $attributes->class('relative w-full overflow-x-auto') }} role="region" aria-label="{{ $caption }}" tabindex="0">
    <table class="w-full caption-bottom text-left text-sm [&_td]:px-5 [&_td]:py-3 [&_td]:align-middle [&_td]:whitespace-nowrap [&_th]:h-10 [&_th]:px-5 [&_th]:text-start [&_th]:align-middle [&_th]:font-medium [&_th]:whitespace-nowrap">
        <caption class="sr-only">{{ $caption }}</caption>
        <thead class="border-b border-border bg-muted/50 text-foreground">
            {{ $head }}
        </thead>
        <tbody class="divide-y divide-border text-foreground [&_tr]:transition-colors [&_tr:hover]:bg-muted/50">
            {{ $slot }}
        </tbody>
    </table>
</div>
