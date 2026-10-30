<x-layouts::app title="Recovery codes">
    <h1 class="mb-2 text-xl font-semibold">Save your recovery codes</h1>
    <p class="mb-6 text-sm">Each code works once, for when your authenticator is unavailable. They are shown only now and cannot be displayed again.</p>

    <ul class="mb-6 grid grid-cols-1 gap-1 rounded border border-gray-300 bg-white p-4 font-mono text-sm">
        @foreach ($recoveryCodes as $recoveryCode)
            <li>{{ $recoveryCode }}</li>
        @endforeach
    </ul>

    <a href="{{ route('dashboard') }}" class="block w-full rounded bg-gray-900 px-4 py-2 text-center font-medium text-white">I have saved them</a>
</x-layouts::app>
