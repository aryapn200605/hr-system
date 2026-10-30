<div>
    <h1 class="mb-2 text-xl font-semibold">Dashboard</h1>
    <p class="mb-6 text-sm">Signed in as {{ auth()->user()->name }}.</p>

    <button type="button" wire:click="$refresh" class="mb-4 rounded border border-gray-900 px-4 py-2 text-sm font-medium">Refresh</button>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="text-sm underline">Log out</button>
    </form>
</div>
