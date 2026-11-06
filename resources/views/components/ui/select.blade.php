@props([
    'label',
    'id' => null,
    'name' => null,
    'hint' => null,
    // value => label, or a list of ['value' => ..., 'label' => ..., 'hint' => ..., 'disabled' => bool].
    'options' => [],
    'multiple' => false,
    // null: a search box when there are more than 8 options.
    'searchable' => null,
    // A Livewire method that returns at most a few options for a search term (large lists).
    'remote' => null,
    // value => label for chosen values that are not in the options (remote lists).
    'labels' => [],
    'value' => null,
    'placeholder' => null,
    'disabled' => false,
    // A multi-select shows "N selected" in its button above this many values.
    'summaryAfter' => 2,
    // Chips shown under a multi-select before "+N".
    'chips' => 3,
    // Offer "Select all" in a multi-select (local lists only).
    'selectAll' => true,
])

{{-- shadcn's Select, and its Popover with Command for the searchable and multiple versions. A button opens a
     list placed with position: fixed from the button (float() in ui.js), so no scrolling parent or dialog clips it. The chosen value is kept by
     Alpine: wire:model on this component binds it (x-modelable), and with a name hidden inputs send it in a
     plain form. See resources/js/ui.js. --}}
@php
    $model = $attributes->wire('model');
    $field = $name ?? $model->value();
    $id ??= $field;
    $hasError = $field && ($errors->has($field) || $errors->has($field.'.*'));
    $errorKey = $field && ! $errors->has($field) && $errors->has($field.'.*') ? collect($errors->getMessages())->keys()->first(fn ($key) => str_starts_with($key, $field.'.')) : $field;
    $describedBy = collect([$hint ? $id.'-hint' : null, $hasError ? $id.'-error' : null])->filter()->implode(' ');

    $list = collect($options)->map(fn ($option, $key) => is_array($option)
        ? ['value' => (string) $option['value'], 'label' => (string) $option['label'], 'hint' => isset($option['hint']) ? (string) $option['hint'] : null, 'disabled' => (bool) ($option['disabled'] ?? false), 'lang' => $option['lang'] ?? null]
        : ['value' => (string) $key, 'label' => (string) $option, 'hint' => null, 'disabled' => false, 'lang' => null])->values();

    $searchable = $remote !== null || ($searchable ?? $list->count() > 8);
    // The dialog pattern (a search box or several choices); otherwise the select-only combobox.
    $popover = $searchable || $multiple;
    $placeholder ??= $multiple ? __('All') : __('Choose…');

    // The value shown before Alpine starts, and the value for a plain form.
    $initial = $value;
    if ($initial === null && $model->value()) {
        $initial = data_get(\Livewire\Livewire::current(), $model->value());
    }
    $initial = $multiple
        ? collect(is_array($initial) ? $initial : ($initial === null || $initial === '' ? [] : [$initial]))->map(fn ($v) => (string) $v)->values()->all()
        : (is_scalar($initial) ? (string) $initial : '');
    $labelMap = collect($labels)->mapWithKeys(fn ($l, $v) => [(string) $v => (string) $l])->all();
    $labelFor = fn (string $v) => $list->firstWhere('value', $v)['label'] ?? ($labelMap[$v] ?? '');
    $shown = $multiple
        ? (count($initial) > $summaryAfter ? __(':count selected', ['count' => count($initial)]) : collect($initial)->map($labelFor)->implode(', '))
        : $labelFor($initial);

    // The x-data expression must not change between Livewire renders, or the morph starts the component
    // again: with wire:model the value arrives through x-modelable, never through this config.
    $config = [
        'id' => $id,
        'multiple' => (bool) $multiple,
        'searchable' => $searchable,
        'remote' => $remote,
        'value' => $model->value() ? ($multiple ? [] : '') : $initial,
        'summaryAfter' => $summaryAfter,
        'text' => ['selected' => __(':count selected')],
    ];
@endphp

<div
    x-data="uiSelect(@js($config))"
    @if ($model->value()) x-modelable="value" {{ $attributes->whereStartsWith('wire:model') }} @endif
    {{ $attributes->whereDoesntStartWith('wire:model')->class('min-w-0') }}
    x-on:focusout="onFocusOut($event)"
    x-on:click.outside="close(false)"
    data-options="{{ json_encode($list) }}"
    data-labels="{{ json_encode((object) $labelMap) }}"
    data-ui-select="{{ $id }}"
>
    <label id="{{ $id }}-label" for="{{ $id }}" class="block text-sm leading-none font-medium text-foreground">{{ $label }}</label>

    <button
        type="button"
        id="{{ $id }}"
        x-ref="trigger"
        @if ($popover)
            aria-haspopup="dialog"
            aria-controls="{{ $id }}-panel"
        @else
            role="combobox"
            aria-haspopup="listbox"
            aria-controls="{{ $id }}-listbox"
            x-bind:aria-activedescendant="activeId"
        @endif
        aria-expanded="false"
        x-bind:aria-expanded="open.toString()"
        aria-labelledby="{{ $id }}-label {{ $id }}-value"
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        @disabled($disabled)
        x-bind:data-state="open ? 'open' : 'closed'"
        x-on:click="toggle()"
        x-on:keydown="onKey($event)"
        @class([
            'mt-2 flex h-9 w-full min-w-0 items-center justify-between gap-2 rounded-md border bg-transparent px-3 py-2 text-start text-base whitespace-nowrap shadow-xs transition-colors disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
            'border-destructive' => $hasError,
            'border-input' => ! $hasError,
        ])
    >
        <span id="{{ $id }}-value" class="min-w-0 truncate" x-bind:class="display === '' && 'text-muted-foreground'" x-text="display === '' ? @js($placeholder) : display" @class(['text-muted-foreground' => $shown === ''])>{{ $shown === '' ? $placeholder : $shown }}</span>
        <x-ui.icon :name="$popover ? 'chevrons-up-down' : 'chevron-down'" class="size-4 shrink-0 text-muted-foreground" />
    </button>

    {{-- Plain form: the chosen value(s) under the field name. --}}
    @if ($name)
        @if ($multiple)
            <template x-for="item in value" x-bind:key="item"><input type="hidden" name="{{ $name }}[]" x-bind:value="item"></template>
        @else
            <input type="hidden" name="{{ $name }}" value="{{ $initial }}" x-bind:value="value">
        @endif
    @endif

    @if ($multiple)
        <div class="mt-2 flex flex-wrap items-center gap-1" x-show="value.length > 0" x-cloak data-chips>
            <template x-for="item in value.slice(0, {{ (int) $chips }})" x-bind:key="item">
                <span class="inline-flex max-w-full items-center gap-1 rounded-md bg-secondary py-0.5 ps-2 pe-0.5 text-xs font-medium text-secondary-foreground" data-chip>
                    <span class="truncate" x-text="labelOf(item)"></span>
                    <button type="button" data-chip-remove class="rounded-sm p-0.5 text-muted-foreground hover:bg-background hover:text-foreground" x-bind:aria-label="@js(__('Remove :name')).replace(':name', labelOf(item))" x-on:click="remove(item)" @disabled($disabled)>
                        <x-ui.icon name="close" class="size-3" />
                    </button>
                </span>
            </template>
            <span class="text-xs text-muted-foreground" x-show="value.length > {{ (int) $chips }}" x-text="@js(__('+:count more')).replace(':count', value.length - {{ (int) $chips }})"></span>
            <button type="button" class="ms-1 rounded-sm px-1 text-xs font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline" x-on:click="clear(); $refs.trigger.focus()" data-chips-clear @disabled($disabled)>{{ __('Clear') }}</button>
        </div>
    @endif

    <div
        id="{{ $id }}-panel"
        x-ref="panel"
        @if ($popover) role="dialog" aria-label="{{ $label }}" x-on:keydown="onKey($event)" @endif
        x-bind:class="{ hidden: ! open }"
        class="fixed z-50 hidden w-max overflow-y-auto rounded-md border border-border bg-popover text-popover-foreground shadow-md"
        data-panel
    >
        @if ($searchable)
            <div class="flex h-9 items-center gap-2 border-b border-border px-3">
                <x-ui.icon name="search" class="size-4 shrink-0 text-muted-foreground" />
                <input
                    type="text"
                    x-ref="search"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-expanded="true"
                    aria-controls="{{ $id }}-listbox"
                    x-bind:aria-activedescendant="activeId"
                    aria-label="{{ __('Search :label', ['label' => $label]) }}"
                    autocomplete="off"
                    maxlength="100"
                    placeholder="{{ __('Search…') }}"
                    x-model="query"
                    x-on:input="onQuery()"
                    class="flex h-9 w-full min-w-0 bg-transparent py-2 text-base outline-hidden placeholder:text-muted-foreground focus-visible:outline-none md:text-sm"
                >
            </div>
        @endif

        <ul
            id="{{ $id }}-listbox"
            x-ref="listbox"
            role="listbox"
            aria-labelledby="{{ $id }}-label"
            @if ($multiple) aria-multiselectable="true" @endif
            @if ($multiple && ! $searchable)
                tabindex="0"
                x-bind:aria-activedescendant="activeId"
            @else
                tabindex="-1"
            @endif
            class="max-h-64 scroll-py-1 overflow-y-auto p-1 focus-visible:outline-none"
            x-show="filtered.length > 0"
            x-on:mousedown.prevent
        >
            <template x-for="(option, index) in filtered" x-bind:key="option.value">
                <li
                    x-bind:id="optionId(index)"
                    role="option"
                    x-bind:aria-selected="isSelected(option.value).toString()"
                    x-bind:aria-disabled="option.disabled ? 'true' : null"
                    x-bind:data-value="option.value"
                    x-bind:lang="option.lang"
                    x-bind:class="{ 'bg-accent text-accent-foreground': index === active, 'pointer-events-none opacity-50': option.disabled }"
                    x-on:click="active = index; choose(option)"
                    x-on:mousemove="if (active !== index) active = index"
                    class="relative flex cursor-default items-center gap-2 rounded-sm py-1.5 ps-2 pe-8 text-sm select-none"
                >
                    @if ($multiple)
                        <span class="flex size-4 shrink-0 items-center justify-center rounded-sm border border-primary" x-bind:class="isSelected(option.value) ? 'bg-primary text-primary-foreground' : '[&_svg]:invisible'">
                            <x-ui.icon name="check" class="size-3.5" />
                        </span>
                    @endif
                    <span class="min-w-0">
                        <span class="block truncate" x-text="option.label"></span>
                        <span class="block truncate text-xs text-muted-foreground" x-show="option.hint" x-text="option.hint"></span>
                    </span>
                    @unless ($multiple)
                        <span class="absolute end-2 flex size-3.5 items-center justify-center" x-show="isSelected(option.value)">
                            <x-ui.icon name="check" class="size-4" />
                        </span>
                    @endunless
                </li>
            </template>
        </ul>

        <p class="px-3 py-6 text-center text-sm text-muted-foreground" x-show="loading && filtered.length === 0">{{ __('Searching…') }}</p>
        <p class="px-3 py-6 text-center text-sm" x-show="! loading && filtered.length === 0" data-empty>{{ __('No results found.') }}</p>

        @if ($multiple)
            <div class="flex items-center justify-between gap-2 border-t border-border p-1">
                @if ($selectAll && $remote === null)
                    <button type="button" class="rounded-sm px-2 py-1.5 text-sm hover:bg-accent hover:text-accent-foreground" x-on:click="selectAll()" x-bind:disabled="filtered.length === 0" data-select-all>{{ __('Select all') }}</button>
                @else
                    <span></span>
                @endif
                <button type="button" class="rounded-sm px-2 py-1.5 text-sm hover:bg-accent hover:text-accent-foreground" x-on:click="clear()" x-bind:disabled="value.length === 0" data-clear>{{ __('Clear') }}</button>
            </div>
        @endif

        <span class="sr-only" aria-live="polite" x-ref="status"></span>
    </div>

    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-2 text-sm text-muted-foreground">{{ $hint }}</p>
    @endif

    @if ($errorKey)
        <x-ui.error :for="$errorKey" :id="$id.'-error'" />
    @endif
</div>
