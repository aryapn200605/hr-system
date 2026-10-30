<x-layouts::app title="Two-factor authentication">
    <h1 class="mb-6 text-xl font-semibold">Two-factor authentication</h1>

    <form method="POST" action="{{ route('two-factor.challenge.store') }}" class="space-y-4">
        @csrf

        <div>
            <label for="code" class="block text-sm font-medium">Six-digit code from your authenticator app</label>
            <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
            @error('code')
                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="w-full rounded bg-gray-900 px-4 py-2 font-medium text-white">Verify</button>
    </form>

    <details class="mt-8" @if ($errors->has('recovery_code')) open @endif>
        <summary class="cursor-pointer text-sm font-medium">Use a recovery code</summary>

        <form method="POST" action="{{ route('two-factor.recovery.store') }}" class="mt-4 space-y-4">
            @csrf

            <div>
                <label for="recovery_code" class="block text-sm font-medium">Recovery code</label>
                <input id="recovery_code" name="recovery_code" type="text" required autocomplete="off" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
                @error('recovery_code')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="w-full rounded border border-gray-900 px-4 py-2 font-medium">Use recovery code</button>
        </form>
    </details>

    <form method="POST" action="{{ route('two-factor.cancel') }}" class="mt-6">
        @csrf
        <button type="submit" class="text-sm underline">Cancel</button>
    </form>
</x-layouts::app>
