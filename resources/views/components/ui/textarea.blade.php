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
    <label for="{{ $id }}" class="block text-sm leading-none font-medium text-foreground">{{ $label }}</label>

    <textarea
        id="{{ $id }}"
        rows="{{ $rows }}"
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->class([
            'mt-2 flex min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs transition-colors placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
            'border-destructive' => $hasError,
            'border-input' => ! $hasError,
        ]) }}
    ></textarea>

    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-2 text-sm text-muted-foreground">{{ $hint }}</p>
    @endif

    <x-ui.error :for="$field" :id="$id.'-error'" />
</div>
