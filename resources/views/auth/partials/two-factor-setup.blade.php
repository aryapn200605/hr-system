{{-- Shared by the forced enrollment and the activation's second step: the local QR code (rendered on the server,
     no external service), the manual key, and the code from the app. --}}
<ol class="mb-6 grid gap-5">
    <li class="grid gap-3">
        <p class="text-sm font-medium text-foreground">{{ __('1. Scan this QR code with Google Authenticator or Microsoft Authenticator.') }}</p>
        <div class="flex justify-center rounded-lg border border-border bg-white p-3 [&_svg]:size-44 sm:[&_svg]:size-48">{!! $qrCode !!}</div>
    </li>

    <li class="grid gap-2">
        <p class="text-sm text-muted-foreground">{{ __('Cannot scan it? Enter this key manually:') }}</p>
        <p class="rounded-md border border-border bg-muted px-3 py-2 text-center font-mono text-sm tracking-wide wrap-break-word text-foreground select-all" data-manual-key>{{ implode(' ', str_split($secret, 4)) }}</p>
    </li>

    <li>
        <form method="POST" action="{{ $action }}" class="grid gap-4">
            @csrf

            <x-ui.otp-input script="vanilla" :label="__('2. Enter the six-digit code the app shows')" id="code" name="code" required autofocus />

            <x-ui.submit-button>{{ __('Confirm') }}</x-ui.submit-button>
        </form>
    </li>
</ol>
