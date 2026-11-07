@props(['label', 'id' => 'code', 'name' => null, 'hint' => null, 'value' => '', 'disabled' => false, 'error' => null, 'autofocus' => false, 'required' => false, 'script' => 'alpine', 'srLabel' => false])

{{-- shadcn's input OTP: one real input (autocomplete="one-time-code", so a code from an SMS or a password manager
     fills it; paste and delete stay native) laid transparently over six boxes (two groups of three with a
     separator) that only show its digits. Styles: .otp-* in resources/css/app.css. The label, the row and the
     messages are centred in the field's width.
     script="alpine" (default): Alpine, for Livewire pages; wire:model on the component.
     script="vanilla": plain JavaScript from resources/js/auth.js, for the auth pages, which load no Alpine. Until
     that script runs (or without JavaScript) the boxes stay hidden and the input is an ordinary visible field,
     so the form still works; the script sets data-enhanced. Same markup in both. --}}
@php
    $vanilla = $script === 'vanilla';
    $model = $attributes->wire('model');
    $field = $name ?? $model->value() ?? $id;
    $message = $error ?? ($errors->has($field) ? $errors->first($field) : null);
    $describedBy = collect([$hint ? $id.'-hint' : null, $message ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div
    @if ($vanilla)
        data-otp="{{ $id }}"
    @else
        x-data="uiOtp(@js(['value' => (string) $value, 'length' => 6]))"
        @if ($model->value()) x-modelable="value" {{ $attributes->whereStartsWith('wire:model') }} @endif
        data-ui-otp="{{ $id }}"
        data-enhanced
    @endif
    @if ($message) data-otp-invalid @endif
    @if ($disabled) data-otp-disabled @endif
    {{ $attributes->whereDoesntStartWith('wire:model')->class('otp-field text-center') }}
>
    <label for="{{ $id }}" @class(['block text-sm leading-none font-medium text-foreground', 'sr-only' => $srLabel])>{{ $label }}</label>

    <div class="otp-row mt-3">
        <div class="otp-boxes" aria-hidden="true" data-otp-boxes>
            @foreach ([[0, 1, 2], [3, 4, 5]] as $group)
                @if (! $loop->first)
                    <div class="otp-sep"><span class="h-0.5 w-2.5 rounded-full bg-muted-foreground/60"></span></div>
                @endif
                <div class="otp-group">
                    @foreach ($group as $i)
                        <div
                            class="otp-slot"
                            @unless ($vanilla)
                                x-bind:data-active="activeIndex === {{ $i }}"
                                x-bind:data-filled="char({{ $i }}) !== ''"
                            @endunless
                            data-slot="{{ $i }}"
                        >
                            <span data-otp-char @unless ($vanilla) x-text="char({{ $i }})" @endunless></span>
                            <span
                                class="pointer-events-none absolute inset-0 flex items-center justify-center"
                                data-otp-caret
                                @if ($vanilla) hidden @else x-show="activeIndex === {{ $i }} && char({{ $i }}) === ''" x-cloak @endif
                            >
                                <span class="h-[45%] w-px animate-pulse bg-foreground motion-reduce:animate-none"></span>
                            </span>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>

        <input
            @unless ($vanilla) x-ref="input" @endunless
            id="{{ $id }}"
            @if ($name) name="{{ $name }}" @endif
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            pattern="[0-9]{6}"
            maxlength="6"
            spellcheck="false"
            value="{{ $value }}"
            @unless ($vanilla)
                x-model="value"
                x-on:focus="focused = true; keepCaretAtEnd()"
                x-on:blur="focused = false"
                x-on:click="keepCaretAtEnd()"
                x-on:keyup="keepCaretAtEnd()"
                x-on:select="keepCaretAtEnd()"
                x-on:paste.prevent="paste($event)"
            @endunless
            @if ($message) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @disabled($disabled)
            @required($required)
            @if ($autofocus) autofocus @endif
            class="otp-input disabled:cursor-not-allowed"
            data-otp-input
        >
    </div>

    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-3 text-sm text-muted-foreground">{{ $hint }}</p>
    @endif

    @if ($message)
        <p id="{{ $id }}-error" class="mt-3 text-sm text-destructive" role="alert">{{ $message }}</p>
    @endif
</div>
