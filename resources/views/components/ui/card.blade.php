@props(['title' => null, 'description' => null, 'icon' => null, 'tone' => 'primary', 'actions' => null, 'footer' => null])

{{-- shadcn's Card: rounded-xl, border, card colours and the soft shadow of the auth card (15c), lighter here because
     a page holds several. The body keeps its own padding.
     Phase 15d (section cards): icon (a tinted chip beside the title), actions (a slot on the right of the
     header) and footer (a muted bar with the card's buttons). --}}
<section {{ $attributes->class('flex flex-col rounded-xl border border-border bg-card text-card-foreground shadow-md shadow-slate-900/[0.04]') }}>
    @if ($title)
        <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4" data-card-header>
            <div class="flex min-w-0 items-start gap-3">
                @if ($icon)
                    <x-ui.icon-chip :icon="$icon" :tone="$tone" size="sm" class="-my-0.5" />
                @endif
                <div class="min-w-0">
                    <h2 @class(['leading-none font-semibold', 'mt-1.5' => $icon && ! $description])>{{ $title }}</h2>
                    @if ($description)
                        <p class="mt-1.5 text-sm text-muted-foreground">{{ $description }}</p>
                    @endif
                </div>
            </div>
            @if ($actions !== null && ! ($actions instanceof \Illuminate\View\ComponentSlot && $actions->isEmpty()))
                <div class="flex shrink-0 flex-wrap items-center gap-2" data-card-actions>{{ $actions }}</div>
            @endif
        </header>
    @endif

    {{ $slot }}

    @if ($footer !== null && ! ($footer instanceof \Illuminate\View\ComponentSlot && $footer->isEmpty()))
        <footer class="mt-auto flex flex-wrap items-center gap-2 rounded-b-xl border-t border-border bg-muted/40 px-5 py-3.5" data-card-footer>{{ $footer }}</footer>
    @endif
</section>
