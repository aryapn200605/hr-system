@props(['label', 'options', 'value', 'property', 'dataAttribute' => null])

{{-- shadcn's Tabs list as a toggle group: a muted track with the chosen option raised. Below 640 px a long option
     wraps onto two lines instead of pushing the track past the screen (Phase 15e, 320 px). Each button sets a Livewire
     property ($set) and says whether it is the chosen one (aria-pressed). The label sits above, like a field's, so
     the control lines up with the selects of a toolbar. --}}
@php($labelId = 'segmented-'.$property.'-label')
<div {{ $attributes->class('min-w-0') }} data-segmented="{{ $property }}">
    <span id="{{ $labelId }}" class="block text-sm leading-none font-medium text-foreground">{{ $label }}</span>
    <div class="mt-2 inline-flex min-h-9 max-w-full items-stretch rounded-lg bg-muted p-[3px]" role="group" aria-labelledby="{{ $labelId }}">
        @foreach ($options as $optionValue => $optionLabel)
            <button
                type="button"
                wire:click="$set('{{ $property }}', '{{ $optionValue }}')"
                aria-pressed="{{ $value === $optionValue ? 'true' : 'false' }}"
                @if ($dataAttribute) data-{{ $dataAttribute }}="{{ $optionValue }}" @endif
                @class([
                    'inline-flex min-w-0 cursor-pointer items-center justify-center rounded-md px-3 py-1 text-center text-sm font-medium text-balance transition-[color,box-shadow] motion-reduce:transition-none sm:whitespace-nowrap',
                    'bg-background text-foreground shadow-sm' => $value === $optionValue,
                    'text-slate-600 hover:text-foreground' => $value !== $optionValue,
                ])
            >{{ $optionLabel }}</button>
        @endforeach
    </div>
</div>
