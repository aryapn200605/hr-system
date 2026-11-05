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
    <label for="{{ $id }}" class="block text-sm leading-none font-medium text-foreground">{{ $label }}</label>

    <input
        id="{{ $id }}"
        type="{{ $type }}"
        @if ($name) name="{{ $name }}" @endif
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->class([
            'mt-2 flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-colors placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50 md:text-sm file:me-3 file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground',
            'border-destructive' => $hasError,
            'border-input' => ! $hasError,
        ]) }}
    >

    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-2 text-sm text-muted-foreground">{{ $hint }}</p>
    @endif

    <x-ui.error :for="$field" :id="$id.'-error'" />
</div>
