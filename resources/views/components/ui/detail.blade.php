@props(['label'])

<div {{ $attributes->class('flex items-start justify-between gap-4 py-2.5') }}>
    <dt class="shrink-0 text-sm text-muted-foreground">{{ $label }}</dt>
    <dd class="min-w-0 text-right text-sm font-medium wrap-anywhere text-foreground">{{ $slot }}</dd>
</div>
