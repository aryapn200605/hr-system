@props(['label'])

<div {{ $attributes->class('flex items-start justify-between gap-4 py-2.5') }}>
    <dt class="shrink-0 text-sm text-slate-600">{{ $label }}</dt>
    <dd class="min-w-0 text-right text-sm font-medium wrap-anywhere text-slate-900">{{ $slot }}</dd>
</div>
