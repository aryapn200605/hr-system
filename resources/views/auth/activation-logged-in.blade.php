<x-layouts::auth :title="__('You are logged in')">
    <x-ui.auth-heading icon="user" :title="__('You are logged in as someone else')">{{ __('This browser is logged in to another account, so the invitation link was not opened. Log out, then open the link from your email again.') }}</x-ui.auth-heading>

    <form method="POST" action="{{ route('logout') }}" class="grid gap-3">
        @csrf
        <x-ui.submit-button icon="log-out">{{ __('Log out') }}</x-ui.submit-button>
    </form>
</x-layouts::auth>
