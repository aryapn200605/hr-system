@props(['steps', 'current'])

{{-- A step indicator for multi-step flows (activation, forced enrollment). $steps: labels in order; $current:
     1-based. The current step carries aria-current="step"; the "Step x of y" line is for everyone. --}}
@php($total = count($steps))

<nav {{ $attributes->class('mb-6') }} aria-label="{{ __('Progress') }}">
    <p class="text-xs font-medium text-muted-foreground">{{ __('Step :current of :total', ['current' => $current, 'total' => $total]) }}</p>
    <ol class="mt-2 flex items-center gap-2">
        @foreach ($steps as $i => $label)
            @php($n = $i + 1)
            <li class="flex min-w-0 flex-1 flex-col gap-1.5" @if ($n === $current) aria-current="step" @endif>
                <span @class([
                    'h-1.5 rounded-full',
                    'bg-primary' => $n <= $current,
                    'bg-muted' => $n > $current,
                ])></span>
                <span @class([
                    'truncate text-xs',
                    'font-medium text-foreground' => $n === $current,
                    'text-muted-foreground' => $n !== $current,
                ])>
                    {{ $label }}
                    @if ($n < $current)<span class="sr-only">({{ __('done') }})</span>@endif
                </span>
            </li>
        @endforeach
    </ol>
</nav>
