@props(['label', 'value', 'hint' => null])

<div {{ $attributes->class('px-4 py-4 sm:px-5') }}>
    <p class="text-sm text-muted">{{ $label }}</p>
    <p class="mt-1 text-3xl font-semibold tracking-tight text-ink tabular-nums">{{ $value }}</p>

    @if ($hint)
        <p class="mt-1 text-xs text-muted">{{ $hint }}</p>
    @endif
</div>
