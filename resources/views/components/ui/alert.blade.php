@props(['tone' => 'info'])

<p {{ $attributes->class([
    'rounded-lg border px-3 py-2.5 text-sm',
    match ($tone) {
        'info' => 'border-blue-200 bg-blue-50 text-blue-900',
        'success' => 'border-green-200 bg-green-50 text-green-900',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
        'danger' => 'border-red-200 bg-red-50 text-red-900',
    },
]) }} role="status">{{ $slot }}</p>
