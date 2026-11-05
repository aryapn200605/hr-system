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
    <label for="{{ $id }}" class="block text-sm leading-none font-medium text-foreground">{{ $label }}</label>

    <select
        id="{{ $id }}"
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->class([
            'mt-2 flex h-9 w-full min-w-0 rounded-md border bg-transparent py-1 ps-3 pe-8 text-base shadow-xs transition-colors disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
            'border-destructive' => $hasError,
            'border-input' => ! $hasError,
        ]) }}
    >
        {{ $slot }}
    </select>

    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-2 text-sm text-muted-foreground">{{ $hint }}</p>
    @endif

    <x-ui.error :for="$field" :id="$id.'-error'" />
</div>
