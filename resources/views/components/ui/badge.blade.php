@props(['tone' => 'gray'])

<span data-tone="{{ $tone }}" {{ $attributes->class([
    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset',
    match ($tone) {
        'gray' => 'bg-slate-100 text-slate-700 ring-slate-300',
        'blue' => 'bg-blue-50 text-blue-800 ring-blue-200',
        'purple' => 'bg-purple-50 text-purple-800 ring-purple-200',
        'green' => 'bg-green-50 text-green-800 ring-green-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'red' => 'bg-red-50 text-red-800 ring-red-200',
    },
]) }}>{{ $slot }}</span>
