@props([
    'label',
    'name',
    'id' => null,
    'hint' => null,
    'value' => '',
])

@php
    $id ??= $name;
    $hasError = $errors->has($name);
    $describedBy = collect([$hint ? $id.'-hint' : null, $hasError ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div>
    <label for="{{ $id }}" class="block text-sm font-medium text-ink-soft">{{ $label }}</label>

    <textarea
        id="{{ $id }}"
        name="{{ $name }}"
        rows="4"
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->class([
            'mt-1.5 w-full rounded-sm border bg-surface px-3 py-2 text-sm text-ink placeholder:text-muted',
            'border-tone-red' => $hasError,
            'border-rule-strong' => ! $hasError,
        ]) }}
    >{{ $value }}</textarea>

    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-xs text-muted">{{ $hint }}</p>
    @endif

    <x-ui.error :for="$name" :id="$id.'-error'" />
</div>
