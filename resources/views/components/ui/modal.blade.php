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
    <div class="fixed inset-0 bg-slate-900/50" x-on:click="open = false"></div>

    <div {{ $attributes->class('relative max-h-full w-full max-w-md overflow-y-auto rounded-xl bg-white shadow-xl') }}>
        <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-3.5">
            <h2 id="{{ $name }}-title" class="text-base font-semibold text-slate-900">{{ $title }}</h2>
            <button type="button" class="cursor-pointer rounded-lg p-1 text-slate-600 hover:bg-slate-100" aria-label="{{ __('Close') }}" x-on:click="open = false">
                <x-ui.icon name="close" class="size-5" />
            </button>
        </div>

        <div class="px-5 py-4 text-sm text-slate-700">{{ $slot }}</div>

        @if ($footer)
            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 px-5 py-3.5 sm:flex-row sm:justify-end">{{ $footer }}</div>
        @endif
    </div>
</div>
