@props(['legend', 'id', 'date', 'hour', 'dateLabel' => null, 'hourLabel' => null, 'min' => null, 'max' => null, 'today' => null])

{{-- A date and a whole hour as one group (overtime start and end): the date picker beside the hour select.
     $date and $hour are Livewire property names; the fields are "{id}-date" and "{id}-hour". --}}
<fieldset {{ $attributes->class('min-w-0') }} data-date-hour="{{ $id }}">
    <legend class="sr-only">{{ $legend }}</legend>
    <div class="grid grid-cols-[minmax(0,1fr)_7.5rem] gap-2">
        <x-ui.date-picker :label="$dateLabel ?? $legend" :id="$id.'-date'" :min="$min" :max="$max" :today="$today" wire:model="{{ $date }}" />
        <x-ui.hour-select :label="$hourLabel ?? __('Hour')" :id="$id.'-hour'" placeholder="--:00" wire:model="{{ $hour }}" />
    </div>
</fieldset>
