@props([
    'label',
    'name',
    'id' => null,
    'hint' => null,
    'describedBy' => null,
    'icon' => 'lock',
])

{{-- A password field with a show/hide button, after the reference's PasswordInput. The button is plain
     JavaScript (resources/js/auth.js, data-password-toggle) and stays hidden until that script runs, so without
     JavaScript this is an ordinary password field. Pass autocomplete (current-password or new-password). --}}
@php
    $id ??= $name;
    $hasError = $errors->has($name);
    $describedBy = collect([$describedBy, $hint ? $id.'-hint' : null, $hasError ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div>
    <div class="flex items-center justify-between gap-2">
        <label for="{{ $id }}" class="block text-sm leading-none font-medium text-foreground">{{ $label }}</label>
        {{ $action ?? '' }}
    </div>

    <div class="relative mt-2">
        @if ($icon)
            <x-ui.icon :name="$icon" class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        @endif
        <input
            id="{{ $id }}"
            type="password"
            name="{{ $name }}"
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class([
                'flex h-9 w-full min-w-0 rounded-md border bg-transparent py-1 pe-10 text-base shadow-xs transition-colors placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                'ps-9' => $icon,
                'ps-3' => ! $icon,
                'border-destructive' => $hasError,
                'border-input' => ! $hasError,
            ]) }}
        >
        <button
            type="button"
            class="absolute end-1 top-1/2 flex size-7 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
            aria-controls="{{ $id }}"
            aria-label="{{ __('Show password') }}"
            data-label-show="{{ __('Show password') }}"
            data-label-hide="{{ __('Hide password') }}"
            data-password-toggle
            hidden
        >
            <x-ui.icon name="eye" class="size-4" data-icon="show" />
            <x-ui.icon name="eye-off" class="size-4" data-icon="hide" hidden />
        </button>
    </div>

    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-2 text-sm text-muted-foreground">{{ $hint }}</p>
    @endif

    <x-ui.error :for="$name" :id="$id.'-error'" />
</div>
