@props(['name', 'title', 'footer' => null, 'description' => null, 'icon' => null, 'tone' => 'default'])

{{-- shadcn's Dialog. Always rendered and only shown or hidden, so its focus trap never outlives it.
     Open with $dispatch('open-modal', { name: '...' }), or dispatch('open-modal', name: '...') from Livewire.
     Styled like the auth card (15c): rounded-2xl, soft shadow, the same short entrance (motion-safe only), and an
     optional icon badge beside the title. icon: an x-ui.icon name; tone: default (primary tint), danger, warning. --}}
<div
    x-data="{ open: false }"
    x-on:open-modal.window="if ($event.detail.name === @js($name)) open = true"
    x-on:close-modal.window="if ($event.detail.name === @js($name)) open = false"
    x-on:keydown.escape.window="open = false"
    x-bind:class="{ hidden: ! open, flex: open }"
    x-trap.noscroll="open"
    class="fixed inset-0 z-50 hidden items-end justify-center p-4 sm:items-center"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $name }}-title"
>
    <div class="fixed inset-0 bg-slate-950/50" x-on:click="open = false"></div>

    <div {{ $attributes->class('relative grid max-h-full w-full max-w-lg overflow-y-auto rounded-2xl border border-border bg-background text-foreground shadow-xl shadow-slate-900/10 motion-safe:animate-auth-enter') }} data-modal-panel>
        <div class="flex items-start justify-between gap-4 px-6 pt-6 pb-2">
            <div class="flex min-w-0 items-start gap-4">
                @if ($icon)
                    <span @class([
                        'flex size-10 shrink-0 items-center justify-center rounded-full ring-4',
                        'bg-primary/10 text-primary ring-primary/5' => $tone === 'default',
                        'bg-red-50 text-red-700 ring-red-50/50' => $tone === 'danger',
                        'bg-amber-50 text-amber-800 ring-amber-50/50' => $tone === 'warning',
                    ]) aria-hidden="true" data-modal-icon="{{ $tone }}">
                        <x-ui.icon :name="$icon" class="size-5" />
                    </span>
                @endif
                <div @class(['min-w-0 space-y-2 text-start', 'pt-2.5' => $icon && ! $description, 'pt-0.5' => $icon && $description])>
                    <h2 id="{{ $name }}-title" class="text-lg leading-tight font-semibold tracking-tight">{{ $title }}</h2>
                    @if ($description)
                        <p class="text-sm text-muted-foreground">{{ $description }}</p>
                    @endif
                </div>
            </div>
            <button type="button" class="-me-2 -mt-2 rounded-sm p-1.5 text-muted-foreground opacity-70 transition-opacity hover:opacity-100" aria-label="{{ __('Close') }}" x-on:click="open = false">
                <x-ui.icon name="close" class="size-4" />
            </button>
        </div>

        <div class="px-6 py-4 text-sm text-foreground">{{ $slot }}</div>

        @if ($footer)
            <div class="flex flex-col-reverse gap-2 border-t border-border bg-muted/40 px-6 py-4 sm:flex-row sm:justify-end">{{ $footer }}</div>
        @endif
    </div>
</div>
