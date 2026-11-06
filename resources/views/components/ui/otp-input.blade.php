@props(['label', 'id' => 'code', 'name' => null, 'hint' => null, 'value' => '', 'disabled' => false, 'error' => null, 'autofocus' => false])

{{-- shadcn's input OTP: one real input (autocomplete="one-time-code", so a code from an SMS or a password manager
     fills it; paste and delete stay native) laid transparently over six boxes that only show its digits. Pure
     Alpine, for the controller + Blade auth pages too. Livewire: wire:model on the component. --}}
@php
    $model = $attributes->wire('model');
    $field = $name ?? $model->value() ?? $id;
    $message = $error ?? ($errors->has($field) ? $errors->first($field) : null);
    $describedBy = collect([$hint ? $id.'-hint' : null, $message ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div
    x-data="uiOtp(@js(['value' => (string) $value, 'length' => 6]))"
    @if ($model->value()) x-modelable="value" {{ $attributes->whereStartsWith('wire:model') }} @endif
    {{ $attributes->whereDoesntStartWith('wire:model') }}
    data-ui-otp="{{ $id }}"
>
    <label for="{{ $id }}" class="block text-sm leading-none font-medium text-foreground">{{ $label }}</label>

    <div @class(['relative mt-2 inline-flex', 'opacity-50' => $disabled])>
        <div class="flex items-center" aria-hidden="true">
            @for ($i = 0; $i < 6; $i++)
                <div
                    @class([
                        'relative flex size-10 items-center justify-center border-y border-e bg-background text-base font-medium shadow-xs transition-shadow',
                        'rounded-s-md border-s' => $i === 0,
                        'rounded-e-md' => $i === 5,
                        'border-destructive' => $message,
                        'border-input' => ! $message,
                    ])
                    x-bind:class="activeIndex === {{ $i }} && 'z-10 outline-2 outline-ring'"
                    data-slot="{{ $i }}"
                >
                    <span x-text="char({{ $i }})"></span>
                    <span class="pointer-events-none absolute inset-0 flex items-center justify-center" x-show="activeIndex === {{ $i }} && char({{ $i }}) === ''" x-cloak>
                        <span class="h-4 w-px animate-pulse bg-foreground"></span>
                    </span>
                </div>
            @endfor
        </div>

        <input
            x-ref="input"
            id="{{ $id }}"
            @if ($name) name="{{ $name }}" @endif
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            pattern="[0-9]{6}"
            maxlength="6"
            spellcheck="false"
            value="{{ $value }}"
            x-model="value"
            x-on:focus="focused = true; keepCaretAtEnd()"
            x-on:blur="focused = false"
            x-on:click="keepCaretAtEnd()"
            x-on:keyup="keepCaretAtEnd()"
            x-on:select="keepCaretAtEnd()"
            x-on:paste.prevent="paste($event)"
            @if ($message) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @disabled($disabled)
            @if ($autofocus) autofocus @endif
            class="absolute inset-0 h-full w-full cursor-text bg-transparent font-mono text-transparent caret-transparent outline-none selection:bg-transparent focus-visible:outline-none disabled:cursor-not-allowed"
            style="letter-spacing: -0.5em;"
            data-otp-input
        >
    </div>

    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-2 text-sm text-muted-foreground">{{ $hint }}</p>
    @endif

    @if ($message)
        <p id="{{ $id }}-error" class="mt-2 text-sm text-destructive">{{ $message }}</p>
    @endif
</div>
