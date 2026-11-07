@props([
    'title' => null,
    'subtitle' => null,
    'actions' => null,
    'width' => 'default',
    'icon' => null,
    'back' => null,
])

{{-- The page container: the only place that sets the width, the centring and the padding of a page (shadcn-admin's
     Main). Title and subtitle may be text or slots; actions sit on the right of the title, below it on phones.
     Widths: default (80rem), narrow (32rem, single forms), full (no maximum).
     Phase 15d: icon (an x-ui.icon name, shown in a tinted square beside the title), back (['href' => ...,
     'label' => ...], a small link above the title on detail pages) and a quiet dotted backdrop behind the
     heading. The breadcrumbs stay in the header bar above (one trail per page, set by the page's layout data). --}}
<div
    data-page
    data-width="{{ $width }}"
    {{ $attributes->class([
        'relative isolate mx-auto w-full space-y-6 px-4 py-6 sm:px-6',
        match ($width) {
            'narrow' => 'max-w-lg',
            'full' => 'max-w-none',
            default => 'max-w-7xl',
        },
    ]) }}
>
    {{-- Decoration only: a dotted field that fades out downwards, clipped to the container. --}}
    <div class="page-backdrop pointer-events-none absolute inset-x-0 top-0 -z-10 h-56 overflow-hidden" aria-hidden="true" data-page-backdrop></div>

    @if ($title !== null && (string) $title !== '')
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between" data-page-header>
            <div class="min-w-0">
                @if (is_array($back) && isset($back['href'], $back['label']))
                    <a href="{{ $back['href'] }}" class="group mb-3 inline-flex items-center gap-1.5 rounded-sm text-sm text-muted-foreground transition-colors hover:text-foreground" data-page-back>
                        <x-ui.icon name="arrow-left" class="size-4 transition-transform group-hover:-translate-x-0.5 motion-reduce:transition-none" />
                        {{ $back['label'] }}
                    </a>
                @endif
                <div class="flex min-w-0 items-start gap-3.5">
                    @if ($icon)
                        <x-ui.icon-chip :icon="$icon" size="lg" class="mt-0.5 shadow-xs max-sm:hidden" />
                    @endif
                    <div class="min-w-0">
                        <h1 class="text-2xl font-bold tracking-tight wrap-anywhere">{{ $title }}</h1>
                        @if ($subtitle !== null && (string) $subtitle !== '')
                            <div class="mt-1 text-sm text-muted-foreground" data-page-subtitle>{{ $subtitle }}</div>
                        @endif
                    </div>
                </div>
            </div>

            @if ($actions !== null && ! ($actions instanceof \Illuminate\View\ComponentSlot && $actions->isEmpty()))
                <div class="flex shrink-0 flex-wrap gap-2 self-start" data-page-actions>{{ $actions }}</div>
            @endif
        </header>
    @endif

    {{ $slot }}
</div>
