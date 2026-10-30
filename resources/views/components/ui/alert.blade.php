@props(['tone' => 'info'])

<p {{ $attributes->class([
    'border-l-2 px-3 py-2 text-sm text-ink',
    match ($tone) {
        'info' => 'border-tone-blue bg-tone-blue-wash',
        'success' => 'border-tone-green bg-tone-green-wash',
        'warning' => 'border-tone-amber bg-tone-amber-wash',
        'danger' => 'border-tone-red bg-tone-red-wash',
    },
]) }} role="status">{{ $slot }}</p>
