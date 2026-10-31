{{-- Show one with $dispatch('toast', { message: '...', tone: 'success' }), dispatch('toast', message: '...')
     from Livewire, or session()->flash('toast', '...') before a redirect. Messages go through x-text, never as HTML. --}}
<div
    x-data="{
        toasts: [],
        add(message, tone = 'success') {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message, tone });
            setTimeout(() => this.remove(id), 5000);
        },
        remove(id) {
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        },
    }"
    @if (session('toast')) x-init="add(@js(session('toast')))" @endif
    x-on:toast.window="add($event.detail.message, $event.detail.tone)"
    class="pointer-events-none fixed inset-x-4 bottom-4 z-45 flex flex-col gap-2 sm:left-auto sm:w-80"
    role="status"
    aria-live="polite"
>
    <template x-for="toast in toasts" x-bind:key="toast.id">
        <div
            class="pointer-events-auto flex items-start gap-2.5 rounded-sm border border-rule bg-surface px-3 py-2.5 text-sm text-ink shadow-overlay"
        >
            <span
                class="mt-1.5 size-1.5 shrink-0"
                x-bind:class="{
                    'bg-tone-green': toast.tone === 'success',
                    'bg-tone-blue': toast.tone === 'info',
                    'bg-tone-amber': toast.tone === 'warning',
                    'bg-tone-red': toast.tone === 'danger',
                }"
                aria-hidden="true"
            ></span>
            <span class="min-w-0 flex-1" x-text="toast.message"></span>
            <button type="button" class="cursor-pointer rounded-sm text-muted hover:text-ink" aria-label="Dismiss" x-on:click="remove(toast.id)">
                <x-ui.icon name="close" class="size-4" />
            </button>
        </div>
    </template>
</div>
