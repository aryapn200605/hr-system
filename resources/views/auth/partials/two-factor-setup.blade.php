<div class="mb-4 flex justify-center bg-white p-2">{!! $qrCode !!}</div>

<p class="text-sm">Or enter this key manually:</p>
<p class="mb-6 font-mono text-sm break-all">{{ implode(' ', str_split($secret, 4)) }}</p>

<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf

    <div>
        <label for="code" class="block text-sm font-medium">Six-digit code from the app</label>
        <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
        @error('code')
            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
        @enderror
    </div>

    <button type="submit" class="w-full rounded bg-gray-900 px-4 py-2 font-medium text-white">Confirm</button>
</form>
