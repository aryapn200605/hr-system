@props([
    'label',
    'name' => null,
    'id' => null,
    'type' => 'text',
    'hint' => null,
])

@php
    $field = $name ?? $attributes->wire('model')->value();
    $id ??= $field;
    $hasError = $errors->has($field);
    $describedBy = collect([$hint ? $id.'-hint' : null, $hasError ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div>
    <label for="{{ $id }}" class="block text-sm font-medium text-ink">{{ $label }}</label>

    <input
        id="{{ $id }}"
        type="{{ $type }}"
        @if ($name) name="{{ $name }}" @endif
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->class([
            'mt-1 w-full rounded-sm border bg-surface px-2.5 py-1.5 text-sm text-ink tabular-nums placeholder:text-muted',
            'border-tone-red' => $hasError,
            'border-rule-strong' => ! $hasError,
        ]) }}
    >

    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-xs text-muted">{{ $hint }}</p>
    @endif

    <x-ui.error :for="$field" :id="$id.'-error'" />
</div>
