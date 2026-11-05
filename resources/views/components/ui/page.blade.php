@props([
    'title' => null,
    'subtitle' => null,
    'actions' => null,
    'width' => 'default',
])

{{-- The page container: the only place that sets the width, the centring and the padding of a page (shadcn-admin's
     Main). Title and subtitle may be text or slots; actions sit on the right of the title, below it on phones.
     Widths: default (80rem), narrow (32rem, single forms), full (no maximum). --}}
<div
    data-page
    data-width="{{ $width }}"
    {{ $attributes->class([
        'mx-auto w-full space-y-6 px-4 py-6 sm:px-6',
        match ($width) {
            'narrow' => 'max-w-lg',
            'full' => 'max-w-none',
            default => 'max-w-7xl',
        },
    ]) }}
>
    @if ($title !== null && (string) $title !== '')
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between" data-page-header>
            <div class="min-w-0">
                <h1 class="text-2xl font-bold tracking-tight wrap-anywhere">{{ $title }}</h1>
                @if ($subtitle !== null && (string) $subtitle !== '')
                    <div class="mt-1 text-sm text-muted-foreground" data-page-subtitle>{{ $subtitle }}</div>
                @endif
            </div>

            @if ($actions !== null && ! ($actions instanceof \Illuminate\View\ComponentSlot && $actions->isEmpty()))
                <div class="flex shrink-0 flex-wrap gap-2 self-start" data-page-actions>{{ $actions }}</div>
            @endif
        </header>
    @endif

    {{ $slot }}
</div>
