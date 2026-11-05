@props(['label', 'value', 'hint' => null])

<div {{ $attributes->class('rounded-xl border border-slate-200 bg-white px-5 py-4') }}>
    <p class="text-sm text-slate-600">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900" data-value>{{ $value }}</p>

    @if ($hint)
        <p class="mt-1 text-xs text-slate-600">{{ $hint }}</p>
    @endif

    @if (! $slot->isEmpty())
        <div class="mt-2 space-y-1 text-sm text-slate-600">{{ $slot }}</div>
    @endif
</div>
