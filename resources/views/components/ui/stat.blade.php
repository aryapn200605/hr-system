@props(['label', 'value', 'hint' => null])

<div {{ $attributes->class('py-3 sm:px-5 sm:first:pl-0') }}>
    <p class="text-sm text-muted">{{ $label }}</p>
    <p class="mt-0.5 text-3xl font-medium tracking-tight text-ink tabular-nums">{{ $value }}</p>

    @if ($hint)
        <p class="mt-0.5 text-xs text-muted">{{ $hint }}</p>
    @endif
</div>
