@props(['icon', 'tone' => 'primary', 'size' => 'md'])

{{-- A soft square behind an icon (figure cards, card headers, page titles). Each tone keeps the icon at 3:1 or
     more on its tint (non-text contrast). Decorative: the text beside it names the thing. --}}
<span {{ $attributes->class([
    'flex shrink-0 items-center justify-center rounded-lg ring-1 ring-inset',
    match ($size) {
        'sm' => 'size-8',
        'lg' => 'size-11',
        default => 'size-9',
    },
    match ($tone) {
        'green' => 'bg-green-50 text-green-700 ring-green-600/15',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-600/15',
        'red' => 'bg-red-50 text-red-700 ring-red-600/15',
        'violet', 'purple' => 'bg-violet-50 text-violet-700 ring-violet-600/15',
        'teal' => 'bg-teal-50 text-teal-700 ring-teal-600/15',
        'gray' => 'bg-slate-100 text-slate-600 ring-slate-500/15',
        default => 'bg-primary/10 text-primary ring-primary/15',
    },
]) }} aria-hidden="true" data-icon-chip="{{ $tone }}">
    <x-ui.icon :name="$icon" :class="match ($size) { 'sm' => 'size-4', 'lg' => 'size-5', default => 'size-4.5' }" />
</span>
