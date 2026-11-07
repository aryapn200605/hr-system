{{-- Phase 15e: what a Livewire action that failed on the server shows (the interceptor in the layout's head
     dispatches "request-failed" with the status). One dialog per kind of failure, all always rendered (CLAUDE.md
     lessons); safe texts only, never the response body. 419: the session expired, so the only way on is a reload
     (a fresh page, or the login page when the session is gone). --}}
<div
    class="contents"
    x-data
    x-on:request-failed.window="
        let status = $event.detail.status;
        let name = status === 419 ? 'session-expired' : ([403, 404].includes(status) ? 'request-denied' : (status === 429 ? 'request-throttled' : 'request-failed'));
        $dispatch('open-modal', { name });
    "
    data-request-failed
>
    <x-ui.modal name="session-expired" icon="hourglass" tone="warning" :title="__('Your session has expired')">
        {{ __('The page was open for too long, or your session ended, so your last action was not sent. Reload the page to continue.') }}

        <x-slot:footer>
            <x-ui.button x-on:click="window.location.reload()" data-reload><x-ui.icon name="refresh" class="size-4" />{{ __('Reload page') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="request-denied" icon="lock" tone="warning" :title="__('This action is not available')">
        {{ __('You may no longer have access, or the item was changed or removed. Reload the page to see its current state.') }}

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', { name: 'request-denied' })">{{ __('Close') }}</x-ui.button>
            <x-ui.button x-on:click="window.location.reload()" data-reload><x-ui.icon name="refresh" class="size-4" />{{ __('Reload page') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="request-throttled" icon="clock" tone="warning" :title="__('Too many attempts')">
        {{ __('You tried this too often in a short time. Wait a minute, then try again.') }}

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', { name: 'request-throttled' })">{{ __('Close') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="request-failed" icon="alert-triangle" tone="danger" :title="__('Something went wrong')">
        {{ __('Your last action could not be completed. Try again in a moment. If it keeps happening, contact your HR Admin.') }}

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', { name: 'request-failed' })">{{ __('Close') }}</x-ui.button>
            <x-ui.button x-on:click="window.location.reload()" data-reload><x-ui.icon name="refresh" class="size-4" />{{ __('Reload page') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
