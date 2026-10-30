<x-layouts::app title="Activate your account">
    <h1 class="mb-2 text-xl font-semibold">Activate your account</h1>
    <p class="mb-6 text-sm">Step 1 of 2: choose a password of at least 12 characters, with uppercase and lowercase letters and a digit.</p>

    <form method="POST" action="{{ route('activation.password.store') }}" class="space-y-4">
        @csrf

        <div>
            <label for="password" class="block text-sm font-medium">Password</label>
            <input id="password" name="password" type="password" required autofocus autocomplete="new-password" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
            @error('password')
                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
        </div>

        <button type="submit" class="w-full rounded bg-gray-900 px-4 py-2 font-medium text-white">Continue</button>
    </form>
</x-layouts::app>
