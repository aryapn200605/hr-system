@props(['name', 'title', 'footer' => null])

{{-- Open with $dispatch('open-modal', { name: '...' }), or dispatch('open-modal', name: '...') from Livewire. --}}
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
    <div class="fixed inset-0 bg-ink/40" x-on:click="open = false"></div>

    <div {{ $attributes->class('relative max-h-full w-full max-w-md overflow-y-auto rounded-sm bg-surface shadow-overlay') }}>
        <div class="flex items-center justify-between gap-4 border-b border-rule px-4 py-2.5">
            <h2 id="{{ $name }}-title" class="text-sm font-semibold text-ink">{{ $title }}</h2>
            <button type="button" class="cursor-pointer rounded-sm p-1 text-muted hover:bg-wash" aria-label="Close" x-on:click="open = false">
                <x-ui.icon name="close" class="size-5" />
            </button>
        </div>

        <div class="px-4 py-3 text-sm text-ink-soft">{{ $slot }}</div>

        @if ($footer)
            <div class="flex flex-col-reverse gap-2 border-t border-rule px-4 py-2.5 sm:flex-row sm:justify-end">{{ $footer }}</div>
        @endif
    </div>
</div>
