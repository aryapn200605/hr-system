<div class="mb-4 flex justify-center rounded-lg border border-slate-200 bg-white p-2">{!! $qrCode !!}</div>

<p class="text-sm text-slate-600">Or enter this key manually:</p>
<p class="mb-6 font-mono text-sm text-slate-900">{{ implode(' ', str_split($secret, 4)) }}</p>

<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf

    <x-ui.input label="Six-digit code from the app" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code" />

    <x-ui.button type="submit" block>Confirm</x-ui.button>
</form>
