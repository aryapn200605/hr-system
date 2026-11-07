@props(['rows' => 5, 'columns' => 4, 'target' => null])

{{-- Placeholder rows shown while a Livewire request replaces a table (filters, pages). Pair it with the table
     wrapped in wire:loading.delay.remove for the same target: the skeleton only appears when the answer takes
     longer than a moment, so quick answers do not flash. Pulsing stops under prefers-reduced-motion. --}}
<div
    @if ($target) wire:loading.delay.block wire:target="{{ $target }}" @endif
    {{ $attributes->class('hidden') }}
    role="status"
    data-skeleton
>
    <span class="sr-only">{{ __('Loading…') }}</span>
    <div class="divide-y divide-border" aria-hidden="true">
        <div class="flex h-10 items-center gap-6 bg-muted/50 px-5">
            @for ($c = 0; $c < $columns; $c++)
                <span class="h-2.5 flex-1 rounded-full bg-slate-200"></span>
            @endfor
        </div>
        @for ($r = 0; $r < $rows; $r++)
            <div class="flex h-13 items-center gap-6 px-5">
                @for ($c = 0; $c < $columns; $c++)
                    <span @class(['h-3 flex-1 rounded-full bg-slate-100 motion-safe:animate-pulse', 'max-w-24' => $c > 0])></span>
                @endfor
            </div>
        @endfor
    </div>
</div>
