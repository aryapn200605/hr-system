@props(['label', 'value', 'hint' => null, 'icon' => null, 'tone' => 'primary'])

{{-- The reference dashboard's figure card: a small title, a large number, a muted line. Phase 15d: the icon sits in
     a tinted chip (tone: primary, green, amber, red, violet, teal, gray) and the card has the soft shadow. --}}
<div {{ $attributes->class('flex flex-col rounded-xl border border-border bg-card text-card-foreground shadow-md shadow-slate-900/[0.04]') }} data-stat>
    <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-2">
        <p class="text-sm font-medium text-muted-foreground">{{ $label }}</p>
        @if ($icon)
            <x-ui.icon-chip :icon="$icon" :tone="$tone" size="sm" />
        @endif
    </div>
    <div class="px-5 pb-5">
        <p class="text-2xl font-bold tracking-tight" data-value>{{ $value }}</p>

        @if ($hint)
            <p class="mt-1 text-xs text-muted-foreground">{{ $hint }}</p>
        @endif

        @if (! $slot->isEmpty())
            <div class="mt-2 space-y-1 text-sm text-muted-foreground">{{ $slot }}</div>
        @endif
    </div>
</div>
