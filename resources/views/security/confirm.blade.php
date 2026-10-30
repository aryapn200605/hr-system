<x-layouts::app title="Confirm your identity" :breadcrumbs="[
    ['label' => 'Home', 'href' => route('dashboard')],
    ['label' => 'Security Settings', 'href' => route('security.index')],
    ['label' => 'Confirm your identity'],
]">
    <div class="mx-auto max-w-md space-y-6">
        @include('security.partials.page-header', [
            'title' => 'Confirm your identity',
            'description' => 'Enter your password and a second factor to continue. This confirmation lasts for one action, for up to 10 minutes.',
        ])

        <x-ui.card class="p-5">
            <form method="POST" action="{{ route('security.confirm.store') }}" class="space-y-4">
                @csrf

                <x-ui.input label="Password" name="password" type="password" required autofocus autocomplete="current-password" />

                <x-ui.input
                    label="Six-digit code from your authenticator app"
                    name="code"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    autocomplete="one-time-code"
                    hint="A code that was just used to log in is refused. Wait for the next one."
                />

                <details class="border-t border-slate-200 pt-4" @if ($errors->has('recovery_code')) open @endif>
                    <summary class="cursor-pointer rounded text-sm font-medium text-slate-800">Use a recovery code instead</summary>

                    <div class="mt-4">
                        <x-ui.input label="Recovery code" name="recovery_code" autocomplete="off" hint="Leave the six-digit code empty. The recovery code works only once." />
                    </div>
                </details>

                <x-ui.button type="submit" block>Confirm</x-ui.button>
            </form>
        </x-ui.card>

        <p class="text-center">
            <x-ui.button :href="route('security.index')" variant="link">Cancel</x-ui.button>
        </p>
    </div>
</x-layouts::app>
