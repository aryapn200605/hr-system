@props([
    'label',
    'id' => null,
    'hint' => null,
])

@php
    $field = $attributes->wire('model')->value();
    $id ??= $field;
    $hasError = $errors->has($field);
    $describedBy = collect([$hint ? $id.'-hint' : null, $hasError ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div>
    <label for="{{ $id }}" class="block text-sm font-medium text-ink">{{ $label }}</label>

    <select
        id="{{ $id }}"
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->class([
            'mt-1 w-full rounded-sm border bg-surface px-2 py-1.5 text-sm text-ink',
            'border-tone-red' => $hasError,
            'border-rule-strong' => ! $hasError,
        ]) }}
    >
        {{ $slot }}
    </select>

    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-xs text-muted">{{ $hint }}</p>
    @endif

    <x-ui.error :for="$field" :id="$id.'-error'" />
</div>
