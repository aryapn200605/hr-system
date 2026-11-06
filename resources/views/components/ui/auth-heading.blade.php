@props(['title', 'icon' => null, 'tone' => 'default', 'level' => 1])

{{-- The heading of an auth card: an icon badge, a clear title and a muted subtitle, centred.
     tone: default (primary tint), danger, warning. level: 1, or 2 for a second heading on the page. --}}
<div {{ $attributes->class('mb-6 flex flex-col items-center gap-2 text-center') }}>
    @if ($icon)
        <span @class([
            'mb-2 flex size-11 items-center justify-center rounded-full ring-8',
            'bg-primary/10 text-primary ring-primary/5' => $tone === 'default',
            'bg-red-50 text-red-700 ring-red-50/50' => $tone === 'danger',
            'bg-amber-50 text-amber-800 ring-amber-50/50' => $tone === 'warning',
        ])>
            <x-ui.icon :name="$icon" class="size-5" />
        </span>
    @endif

    <h{{ $level }} class="text-2xl font-semibold tracking-tight text-balance text-foreground">{{ $title }}</h{{ $level }}>

    @if (! $slot->isEmpty())
        <p class="max-w-sm text-sm text-pretty text-muted-foreground">{{ $slot }}</p>
    @endif
</div>
