@props(['tone' => 'info'])

<p {{ $attributes->class([
    'rounded-sm border px-3 py-2 text-sm text-ink',
    match ($tone) {
        'info' => 'border-tone-blue/25 bg-tone-blue-wash',
        'success' => 'border-tone-green/25 bg-tone-green-wash',
        'warning' => 'border-tone-amber/30 bg-tone-amber-wash',
        'danger' => 'border-tone-red/25 bg-tone-red-wash',
    },
]) }} role="status">{{ $slot }}</p>
