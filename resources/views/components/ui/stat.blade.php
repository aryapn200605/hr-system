@props(['label', 'value', 'hint' => null, 'icon' => null])

{{-- The reference dashboard's figure card: a small title with an icon, a large number, a muted line. --}}
<div {{ $attributes->class('flex flex-col rounded-xl border border-border bg-card text-card-foreground shadow-sm') }}>
    <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-2">
        <p class="text-sm font-medium">{{ $label }}</p>
        @if ($icon)
            <x-ui.icon :name="$icon" class="size-4 shrink-0 text-muted-foreground" />
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
