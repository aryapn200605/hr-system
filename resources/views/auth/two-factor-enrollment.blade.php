<x-layouts::app title="Set up two-factor authentication">
    <h1 class="mb-2 text-xl font-semibold">Set up two-factor authentication</h1>
    <p class="mb-6 text-sm">Your account needs an authenticator app. Scan this code with Google Authenticator or Microsoft Authenticator.</p>

    @include('auth.partials.two-factor-setup', ['action' => route('two-factor.enrollment.store')])

    <form method="POST" action="{{ route('two-factor.cancel') }}" class="mt-6">
        @csrf
        <button type="submit" class="text-sm underline">Cancel</button>
    </form>
</x-layouts::app>
