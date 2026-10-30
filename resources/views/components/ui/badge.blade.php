@props(['tone' => 'gray', 'filled' => false])

{{-- A dot and plain text by default, so a table is not a wall of pills. Filled is for role labels. --}}
<span data-tone="{{ $tone }}" {{ $attributes->class([
    'inline-flex items-center gap-1.5 text-xs font-medium whitespace-nowrap',
    'text-ink-soft' => ! $filled,
    'rounded-sm px-1.5 py-0.5' => $filled,
    'bg-tone-gray-wash text-tone-gray' => $filled && $tone === 'gray',
    'bg-tone-blue-wash text-tone-blue' => $filled && $tone === 'blue',
    'bg-tone-purple-wash text-tone-purple' => $filled && $tone === 'purple',
    'bg-tone-green-wash text-tone-green' => $filled && $tone === 'green',
    'bg-tone-amber-wash text-tone-amber' => $filled && $tone === 'amber',
    'bg-tone-red-wash text-tone-red' => $filled && $tone === 'red',
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
