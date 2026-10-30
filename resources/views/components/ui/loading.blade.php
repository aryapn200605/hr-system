@props(['label' => 'Loading'])

<div {{ $attributes->class('flex items-center gap-2 py-8 text-sm text-muted') }} role="status">
    <x-ui.spinner />
    <span>{{ $label }}</span>
</div>
