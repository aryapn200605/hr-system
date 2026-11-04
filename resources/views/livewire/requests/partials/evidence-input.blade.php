<div>
    <label for="{{ $inputId }}" class="block text-sm font-medium text-slate-800">{{ __('Evidence (optional)') }}</label>
    <input
        id="{{ $inputId }}"
        type="file"
        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
        aria-describedby="{{ $inputId }}-hint"
        class="mt-1.5 block w-full text-sm text-slate-700 file:mr-3 file:cursor-pointer file:rounded-lg file:border file:border-slate-300 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-800 hover:file:bg-slate-50"
        wire:model="{{ $field }}"
    >
    <p id="{{ $inputId }}-hint" class="mt-1.5 text-sm text-slate-600">{{ __('PDF, JPG or PNG, at most :size MB.', ['size' => app(\App\Services\Requests\Evidence::class)->maxMegabytes()]) }}</p>
    <p class="mt-1.5 text-sm text-slate-600" wire:loading wire:target="{{ $field }}">{{ __('Uploading…') }}</p>
    <x-ui.error :for="$field" :id="$inputId.'-error'" />
</div>
