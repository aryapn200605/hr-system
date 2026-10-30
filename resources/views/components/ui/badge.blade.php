@props(['tone' => 'gray', 'filled' => false])

{{-- A square and plain text by default, so a table is not a wall of pills. Filled is for role labels:
     a hairline and tinted text, so the color names the role without competing with the accent. --}}
<span data-tone="{{ $tone }}" {{ $attributes->class([
    'inline-flex items-center gap-1.5 text-xs/5 font-medium whitespace-nowrap',
    'text-ink-soft' => ! $filled,
    'rounded-sm border px-1.5' => $filled,
    'border-tone-gray/30 text-tone-gray' => $filled && $tone === 'gray',
    'border-tone-blue/30 text-tone-blue' => $filled && $tone === 'blue',
    'border-tone-purple/30 text-tone-purple' => $filled && $tone === 'purple',
    'border-tone-green/30 text-tone-green' => $filled && $tone === 'green',
    'border-tone-amber/30 text-tone-amber' => $filled && $tone === 'amber',
    'border-tone-red/30 text-tone-red' => $filled && $tone === 'red',
]) }}>
    @unless ($filled)
        <span @class([
            'size-1.5 shrink-0',
            match ($tone) {
                'gray' => 'bg-tone-gray',
                'blue' => 'bg-tone-blue',
                'purple' => 'bg-tone-purple',
                'green' => 'bg-tone-green',
                'amber' => 'bg-tone-amber',
                'red' => 'bg-tone-red',
            },
        ]) aria-hidden="true"></span>
    @endunless
    {{ $slot }}
</span>
