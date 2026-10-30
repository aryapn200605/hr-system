@props(['label'])

{{-- Proportional columns, so a long email keeps its width in a narrow section. --}}
<div {{ $attributes->class('grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] items-baseline gap-3 py-2.5') }}>
    <dt class="text-sm text-muted">{{ $label }}</dt>
    <dd class="min-w-0 text-sm wrap-anywhere text-ink tabular-nums">{{ $slot }}</dd>
</div>
