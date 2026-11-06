@props([
    'label',
    'name' => null,
    'id' => null,
    'type' => 'text',
    'hint' => null,
    'icon' => null,
])

@php
    $field = $name ?? $attributes->wire('model')->value();
    $id ??= $field;
    $hasError = $errors->has($field);
    $describedBy = collect([$hint ? $id.'-hint' : null, $hasError ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div>
    <label for="{{ $id }}" class="block text-sm leading-none font-medium text-foreground">{{ $label }}</label>

    {{-- icon: an optional decorative icon inside the field, on the start side. --}}
    @if ($icon)
        <div class="relative mt-2">
            <x-ui.icon :name="$icon" class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
    @endif

        <input
            id="{{ $id }}"
            type="{{ $type }}"
            @if ($name) name="{{ $name }}" @endif
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class([
                'flex h-9 w-full min-w-0 rounded-md border bg-transparent py-1 text-base shadow-xs transition-colors placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50 md:text-sm file:me-3 file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground',
                'mt-2' => ! $icon,
                'ps-9 pe-3' => $icon,
                'px-3' => ! $icon,
                'border-destructive' => $hasError,
                'border-input' => ! $hasError,
            ]) }}
        >
    @if ($icon)
        </div>
    @endif

    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-2 text-sm text-muted-foreground">{{ $hint }}</p>
    @endif

    <x-ui.error :for="$field" :id="$id.'-error'" />
</div>
