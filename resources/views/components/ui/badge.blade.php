@props(['tone' => 'gray'])

{{-- shadcn's Badge shape (rounded-md, border, text-xs). Soft tones, each with 4.5:1 text on its background. --}}
<span data-tone="{{ $tone }}" {{ $attributes->class([
    'inline-flex w-fit shrink-0 items-center justify-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap',
    match ($tone) {
        'gray' => 'border-slate-200 bg-slate-100 text-slate-700',
        'primary', 'blue' => 'border-blue-200 bg-blue-50 text-blue-800',
        'purple' => 'border-violet-200 bg-violet-50 text-violet-800',
        'teal' => 'border-teal-200 bg-teal-50 text-teal-800',
        'green' => 'border-green-200 bg-green-50 text-green-800',
        'amber' => 'border-amber-200 bg-amber-50 text-amber-900',
        'red' => 'border-red-200 bg-red-50 text-red-800',
        'solid' => 'border-transparent bg-primary text-primary-foreground',
        'outline' => 'border-border text-foreground',
    },
]) }}>{{ $slot }}</span>
