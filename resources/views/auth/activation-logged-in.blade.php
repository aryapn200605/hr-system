<x-layouts::auth title="You are logged in">
    <x-ui.auth-heading title="You are logged in as someone else">This browser is logged in to another account, so the invitation link was not opened. Log out, then open the link from your email again.</x-ui.auth-heading>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <x-ui.button type="submit" block>Log out</x-ui.button>
    </form>
</x-layouts::auth>
