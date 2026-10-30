<x-layouts::app title="Log in">
    <h1 class="mb-6 text-xl font-semibold">Log in</h1>

    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf

        <div>
            <label for="identifier" class="block text-sm font-medium">Username or email</label>
            <input id="identifier" name="identifier" type="text" required autofocus autocomplete="username" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
            @error('identifier')
                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
            @error('password')
                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="w-full rounded bg-gray-900 px-4 py-2 font-medium text-white">Continue</button>
    </form>
</x-layouts::app>
