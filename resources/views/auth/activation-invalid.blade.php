<x-layouts::app title="Invitation not valid">
    <h1 class="mb-2 text-xl font-semibold">This invitation is not valid</h1>
    <p class="mb-6 text-sm">The link may have expired, been replaced by a newer one, or been used already. Ask your HR Admin to send a new invitation.</p>

    <a href="{{ route('login') }}" class="text-sm underline">Go to login</a>
</x-layouts::app>
