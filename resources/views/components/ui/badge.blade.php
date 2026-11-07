@props(['tone' => 'gray', 'dot' => false])

{{-- shadcn's Badge shape (rounded-md, border, text-xs). Soft tones, each with 4.5:1 text on its background.
     dot (Phase 15d): a small circle in the text colour before the label, for statuses in tables. --}}
<span data-tone="{{ $tone }}" {{ $attributes->class([
    'inline-flex w-fit shrink-0 items-center justify-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap',
    'gap-1.5' => $dot,
    match ($tone) {
        'gray' => 'border-slate-200 bg-slate-100 text-slate-700',
        'primary', 'blue' => 'border-primary/20 bg-primary/5 text-primary-hover',
        'purple' => 'border-violet-200 bg-violet-50 text-violet-800',
        'teal' => 'border-teal-200 bg-teal-50 text-teal-800',
        'green' => 'border-green-200 bg-green-50 text-green-800',
        'amber' => 'border-amber-200 bg-amber-50 text-amber-900',
        'red' => 'border-red-200 bg-red-50 text-red-800',
        'solid' => 'border-transparent bg-primary text-primary-foreground',
        'outline' => 'border-border text-foreground',
    },
]) }}>@if ($dot)<span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true" data-badge-dot></span>@endif{{ $slot }}</span>
