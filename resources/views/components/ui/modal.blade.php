@props(['name', 'title', 'footer' => null, 'description' => null])

{{-- shadcn's Dialog. Always rendered and only shown or hidden, so its focus trap never outlives it.
     Open with $dispatch('open-modal', { name: '...' }), or dispatch('open-modal', name: '...') from Livewire. --}}
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
    <div class="fixed inset-0 bg-black/50" x-on:click="open = false"></div>

    <div {{ $attributes->class('relative grid max-h-full w-full max-w-lg overflow-y-auto rounded-lg border border-border bg-background text-foreground shadow-lg') }}>
        <div class="flex items-start justify-between gap-4 px-6 pt-6 pb-2">
            <div class="min-w-0 space-y-2 text-start">
                <h2 id="{{ $name }}-title" class="text-lg leading-none font-semibold">{{ $title }}</h2>
                @if ($description)
                    <p class="text-sm text-muted-foreground">{{ $description }}</p>
                @endif
            </div>
            <button type="button" class="-me-2 -mt-2 rounded-sm p-1.5 text-muted-foreground opacity-70 transition-opacity hover:opacity-100" aria-label="{{ __('Close') }}" x-on:click="open = false">
                <x-ui.icon name="close" class="size-4" />
            </button>
        </div>

        <div class="px-6 py-4 text-sm text-foreground">{{ $slot }}</div>

        @if ($footer)
            <div class="flex flex-col-reverse gap-2 px-6 pb-6 sm:flex-row sm:justify-end">{{ $footer }}</div>
        @endif
    </div>
</div>
