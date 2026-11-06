@props([
    'label',
    'id' => null,
    'name' => null,
    'hint' => null,
    'value' => null,
    'min' => null,
    'max' => null,
    // "Today" from the server (WIB), never from the browser's clock.
    'today' => null,
    'disableWeekends' => false,
    // yyyy-mm-dd dates that cannot be chosen, for example holidays.
    'disabledDates' => [],
    'placeholder' => null,
    'disabled' => false,
    // Range mode (filters): two dates. Plain form: startName/endName. Livewire: wireStart/wireEnd (property names).
    'range' => false,
    'startName' => null,
    'endName' => null,
    'startValue' => null,
    'endValue' => null,
    'wireStart' => null,
    'wireEnd' => null,
    // Range mode with Livewire: send the request as soon as a range is chosen.
    'live' => false,
])

{{-- shadcn's date picker: a button and a Calendar in a Popover. Monday first; month and day names from Intl in
     the page language; values are yyyy-mm-dd. Single mode binds with wire:model (x-modelable) or a name; range
     mode sets two properties or two hidden inputs. See resources/js/ui.js. --}}
@php
    $model = $attributes->wire('model');
    $field = $range ? ($wireStart ?? $startName) : ($name ?? $model->value());
    $id ??= $field;
    $errorFields = $range ? array_filter([$wireStart ?? $startName, $wireEnd ?? $endName]) : array_filter([$field]);
    $errorField = collect($errorFields)->first(fn ($f) => $errors->has($f));
    $hasError = $errorField !== null;
    $describedBy = collect([$hint ? $id.'-hint' : null, $hasError ? $id.'-error' : null])->filter()->implode(' ');
    $today ??= today()->toDateString();
    $placeholder ??= $range ? __('Any date') : __('Choose a date');

    $current = \Livewire\Livewire::current();
    if (! $range && $value === null && $model->value()) {
        $value = data_get($current, $model->value());
    }
    if ($range && $wireStart) {
        $startValue ??= data_get($current, $wireStart);
        $endValue ??= data_get($current, $wireEnd);
    }

    $format = function (?string $date) {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }
        try {
            return \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $date)->locale(app()->getLocale())->isoFormat('ll');
        } catch (\Throwable) {
            return '';
        }
    };
    $shown = $range
        ? collect([$format($startValue), $format($endValue)])->filter()->implode(' – ')
        : $format($value);

    // Stable between Livewire renders (see x-ui.select): with Livewire the values come from x-modelable or $wire.
    $config = [
        'id' => $id,
        'range' => (bool) $range,
        'value' => ! $model->value() && is_string($value) ? $value : '',
        'start' => ! $wireStart && is_string($startValue) ? $startValue : '',
        'end' => ! $wireStart && is_string($endValue) ? $endValue : '',
        'wireStart' => $wireStart,
        'wireEnd' => $wireEnd,
        'live' => (bool) $live,
        'today' => $today,
        'min' => $min,
        'max' => $max,
        'disableWeekends' => (bool) $disableWeekends,
        'text' => ['from' => __('From :date'), 'until' => __('Until :date')],
    ];
@endphp

<div
    x-data="uiDatePicker(@js($config))"
    @if (! $range && $model->value()) x-modelable="value" {{ $attributes->whereStartsWith('wire:model') }} @endif
    {{ $attributes->whereDoesntStartWith('wire:model')->class('min-w-0') }}
    x-on:focusout="onFocusOut($event)"
    x-on:click.outside="close(false)"
    data-disabled-dates="{{ json_encode(array_values($disabledDates)) }}"
    data-ui-date="{{ $id }}"
    data-today="{{ $today }}"
>
    <label id="{{ $id }}-label" for="{{ $id }}" class="block text-sm leading-none font-medium text-foreground">{{ $label }}</label>

    <button
        type="button"
        id="{{ $id }}"
        x-ref="trigger"
        aria-haspopup="dialog"
        aria-controls="{{ $id }}-panel"
        aria-expanded="false"
        x-bind:aria-expanded="open.toString()"
        aria-labelledby="{{ $id }}-label {{ $id }}-value"
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        @disabled($disabled)
        x-on:click="toggle()"
        x-on:keydown.arrow-down.prevent="show()"
        @class([
            'mt-2 flex h-9 w-full min-w-0 items-center justify-between gap-2 rounded-md border bg-transparent px-3 py-2 text-start text-base whitespace-nowrap shadow-xs transition-colors disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
            'border-destructive' => $hasError,
            'border-input' => ! $hasError,
        ])
    >
        <span id="{{ $id }}-value" class="min-w-0 truncate" x-bind:class="display === '' && 'text-muted-foreground'" x-text="display === '' ? @js($placeholder) : display" @class(['text-muted-foreground' => $shown === ''])>{{ $shown === '' ? $placeholder : $shown }}</span>
        <x-ui.icon name="calendar" class="size-4 shrink-0 text-muted-foreground" />
    </button>

    @if ($range)
        @if ($startName)
            <input type="hidden" name="{{ $startName }}" value="{{ $startValue }}" x-bind:value="start">
        @endif
        @if ($endName)
            <input type="hidden" name="{{ $endName }}" value="{{ $endValue }}" x-bind:value="end">
        @endif
    @elseif ($name)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}" x-bind:value="value">
    @endif

    <div
        id="{{ $id }}-panel"
        x-ref="panel"
        role="dialog"
        aria-modal="false"
        aria-labelledby="{{ $id }}-month"
        x-bind:class="{ hidden: ! open }"
        x-on:keydown.escape.prevent.stop="close(true)"
        class="fixed z-50 hidden w-max overflow-y-auto rounded-md border border-border bg-popover p-3 text-popover-foreground shadow-md"
        data-panel
    >
        <div class="flex items-center justify-between gap-1">
            <button type="button" class="inline-flex size-8 items-center justify-center rounded-md hover:bg-accent hover:text-accent-foreground" aria-label="{{ __('Previous month') }}" x-on:click="shiftMonth(-1)" data-prev>
                <x-ui.icon name="chevron-left" class="size-4" />
            </button>
            <h2 id="{{ $id }}-month" class="text-sm font-medium" aria-live="polite" x-text="month && monthLabel"></h2>
            <button type="button" class="inline-flex size-8 items-center justify-center rounded-md hover:bg-accent hover:text-accent-foreground" aria-label="{{ __('Next month') }}" x-on:click="shiftMonth(1)" data-next>
                <x-ui.icon name="chevron-right" class="size-4" />
            </button>
        </div>

        <table class="mt-2 w-full border-collapse" role="grid" aria-labelledby="{{ $id }}-month" x-ref="grid">
            <thead>
                <tr>
                    <template x-for="day in weekdays" x-bind:key="day.long">
                        <th scope="col" class="size-9 text-[0.8rem] font-normal text-muted-foreground" x-bind:abbr="day.long" x-text="day.short"></th>
                    </template>
                </tr>
            </thead>
            <tbody>
                <template x-for="week in (open ? weeks : [])" x-bind:key="week[0]">
                    <tr>
                        <template x-for="date in week" x-bind:key="date">
                            <td class="p-0 text-center" x-bind:aria-selected="isChosen(date).toString()"
                                x-bind:class="inRange(date) && 'bg-accent'">
                                <button
                                    type="button"
                                    x-bind:data-date="date"
                                    x-bind:tabindex="date === focused ? 0 : -1"
                                    x-bind:aria-disabled="isDisabled(date) ? 'true' : null"
                                    x-bind:aria-current="date === today ? 'date' : null"
                                    x-bind:aria-label="formatDate(date, 'full')"
                                    x-on:click="pick(date)"
                                    x-on:keydown="onGridKey($event)"
                                    x-text="dayNumber(date)"
                                    class="inline-flex size-9 items-center justify-center rounded-md text-sm"
                                    x-bind:class="{
                                        'bg-primary text-primary-foreground hover:bg-primary': isChosen(date),
                                        'hover:bg-accent hover:text-accent-foreground': ! isChosen(date) && ! isDisabled(date),
                                        'text-muted-foreground': ! inMonth(date) && ! isChosen(date),
                                        'cursor-not-allowed text-muted-foreground line-through opacity-60': isDisabled(date),
                                        'font-semibold underline underline-offset-4': date === today && ! isChosen(date),
                                    }"
                                ></button>
                            </td>
                        </template>
                    </tr>
                </template>
            </tbody>
        </table>

        @if ($range)
            <p class="mt-2 text-xs text-muted-foreground" aria-live="polite" x-text="picking ? @js(__('Now choose the last date.')) : @js(__('Choose the first date.'))"></p>
        @endif

        <div class="mt-2 flex items-center justify-between gap-2 border-t border-border pt-2">
            <button type="button" class="rounded-sm px-2 py-1.5 text-sm hover:bg-accent hover:text-accent-foreground" x-on:click="pickToday()" data-today-button>{{ __('Today') }}</button>
            <button type="button" class="rounded-sm px-2 py-1.5 text-sm hover:bg-accent hover:text-accent-foreground" x-on:click="clearValue()" data-clear>{{ __('Clear') }}</button>
        </div>
    </div>

    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-2 text-sm text-muted-foreground">{{ $hint }}</p>
    @endif

    @if ($errorField)
        <x-ui.error :for="$errorField" :id="$id.'-error'" />
    @endif
</div>
