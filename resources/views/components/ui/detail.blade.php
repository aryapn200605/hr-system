@props(['label'])

<div {{ $attributes->class('grid grid-cols-[8.5rem_minmax(0,1fr)] gap-3 py-2 sm:grid-cols-[12rem_minmax(0,1fr)]') }}>
    <dt class="text-sm text-muted">{{ $label }}</dt>
    <dd class="min-w-0 text-sm wrap-anywhere text-ink tabular-nums">{{ $slot }}</dd>
</div>
