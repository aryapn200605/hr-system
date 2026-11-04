@props([
    'label',
    'id' => null,
    'hint' => null,
    'rows' => 3,
])

@php
    $field = $attributes->wire('model')->value();
    $id ??= $field;
    $hasError = $errors->has($field);
    $describedBy = collect([$hint ? $id.'-hint' : null, $hasError ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div>
    <label for="{{ $id }}" class="block text-sm font-medium text-slate-800">{{ $label }}</label>

    <textarea
        id="{{ $id }}"
        rows="{{ $rows }}"
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->class([
            'mt-1.5 w-full rounded-lg border bg-white px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-500',
            'border-red-600' => $hasError,
            'border-slate-500' => ! $hasError,
        ]) }}
    ></textarea>

    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1.5 text-sm text-slate-600">{{ $hint }}</p>
    @endif

    <x-ui.error :for="$field" :id="$id.'-error'" />
</div>
