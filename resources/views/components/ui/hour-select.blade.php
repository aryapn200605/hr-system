@props(['label', 'id' => null, 'name' => null, 'hint' => null, 'value' => null, 'disabled' => false, 'placeholder' => null])

{{-- A whole hour of the day, 00:00 to 23:00: the minutes are always :00, so no time input with a step is
     needed (see the lesson on step bases in CLAUDE.md). Type the hour to jump to it. Server validation of
     overtime times is unchanged. --}}
@php
    $hours = collect(range(0, 23))->mapWithKeys(fn (int $hour) => [sprintf('%02d:00', $hour) => sprintf('%02d:00', $hour)])->all();
@endphp

<x-ui.select :label="$label" :id="$id" :name="$name" :hint="$hint" :value="$value" :disabled="$disabled" :options="$hours" :searchable="false" :placeholder="$placeholder ?? __('Choose an hour')" {{ $attributes }} data-hour-select />
