<x-layouts::app title="Forgot password">
    <h1 class="mb-2 text-xl font-semibold">Forgot your password?</h1>
    <p class="mb-6 text-sm">Enter your email address. If it can reset a password, we will send a link there.</p>

    @if (session('status'))
        <p class="mb-4 text-sm text-green-800">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium">Email</label>
            <input id="email" name="email" type="email" required autofocus autocomplete="email" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
            @error('email')
                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="w-full rounded bg-gray-900 px-4 py-2 font-medium text-white">Send reset link</button>
    </form>

    <a href="{{ route('login') }}" class="mt-6 block text-sm underline">Back to login</a>
</x-layouts::app>
