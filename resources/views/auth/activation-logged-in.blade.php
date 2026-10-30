<x-layouts::app title="You are logged in">
    <h1 class="mb-2 text-xl font-semibold">You are logged in as someone else</h1>
    <p class="mb-6 text-sm">This browser is logged in to another account, so the invitation link was not opened. Log out, then open the link from your email again.</p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="w-full rounded bg-gray-900 px-4 py-2 font-medium text-white">Log out</button>
    </form>
</x-layouts::app>
