{{-- Show one with $dispatch('toast', { message: '...', tone: 'success' }), dispatch('toast', message: '...')
     from Livewire, or session()->flash('toast', '...') before a redirect. Messages go through x-text, never as HTML.
     Styled after the reference's Sonner toasts. On phones they sit under the header, away from the dialogs
     that open from the bottom; from 640 px they sit at the bottom right. The toast itself lets clicks through
     to what is under it, so it never blocks a button; only its close button takes clicks. --}}
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
    class="pointer-events-none fixed inset-x-4 top-[4.5rem] z-[60] flex flex-col gap-2 sm:inset-x-auto sm:top-auto sm:right-4 sm:bottom-4 sm:w-90"
    role="status"
    aria-live="polite"
    data-toasts
>
    <template x-for="toast in toasts" x-bind:key="toast.id">
        <div class="pointer-events-none flex items-start gap-3 rounded-lg border border-border bg-popover p-4 text-sm text-popover-foreground shadow-lg" data-toast>
            <span class="mt-0.5 size-2 shrink-0 rounded-full" aria-hidden="true" x-bind:class="{
                'bg-success': toast.tone === 'success',
                'bg-muted-foreground': toast.tone === 'info',
                'bg-warning': toast.tone === 'warning',
                'bg-destructive': toast.tone === 'danger',
            }"></span>
            <span class="min-w-0 flex-1" x-text="toast.message"></span>
            <button type="button" class="pointer-events-auto -m-1 shrink-0 rounded-sm p-1 text-muted-foreground hover:text-foreground" aria-label="{{ __('Dismiss') }}" x-on:click="remove(toast.id)">
                <x-ui.icon name="close" class="size-4" />
            </button>
        </div>
    </template>
</div>
