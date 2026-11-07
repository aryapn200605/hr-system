@props(['tone' => 'info', 'title' => null])

{{-- shadcn's Alert: a bordered block with an icon. "info" is the neutral default, so it does not compete with
     the primary blue. --}}
<div {{ $attributes->class([
    'grid w-full grid-cols-[1rem_1fr] items-start gap-x-3 gap-y-0.5 rounded-lg border px-4 py-3 text-sm',
    match ($tone) {
        'info' => 'border-border bg-card text-card-foreground',
        'success' => 'border-green-200 bg-green-50 text-green-900',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
        'danger' => 'border-red-200 bg-red-50 text-red-900',
    },
]) }} role="{{ $tone === 'danger' ? 'alert' : 'status' }}">
    <x-ui.icon :name="match ($tone) { 'success' => 'check-circle', 'warning' => 'alert-triangle', 'danger' => 'x-circle', default => 'info' }" class="mt-0.5 size-4" />
    <div class="min-w-0">
        @if ($title)
            <p class="font-medium tracking-tight">{{ $title }}</p>
        @endif
        <div>{{ $slot }}</div>
    </div>
</div>
