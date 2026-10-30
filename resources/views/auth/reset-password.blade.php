<x-layouts::app title="Reset password">
    <h1 class="mb-2 text-xl font-semibold">Choose a new password</h1>
    <p class="mb-6 text-sm">Use at least 12 characters, with uppercase and lowercase letters and a digit. You will still need your authenticator app to log in.</p>

    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium">Email</label>
            <input id="email" name="email" type="email" required autofocus autocomplete="email" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
            @error('email')
                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium">New password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
            @error('password')
                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
        </div>

        <button type="submit" class="w-full rounded bg-gray-900 px-4 py-2 font-medium text-white">Change password</button>
    </form>
</x-layouts::app>
