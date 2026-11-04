@props(['label' => __('Loading')])

<div {{ $attributes->class('flex items-center justify-center gap-2 py-8 text-sm text-slate-600') }} role="status">
    <x-ui.spinner />
    <span>{{ $label }}</span>
</div>
