{{-- The optional evidence file. The native input stays (keyboard, drop and the file dialog are the browser's own),
     inside a dashed area with an icon; its label, hint, upload state and error are linked to it. --}}
<div>
    <label for="{{ $inputId }}" class="block text-sm font-medium text-foreground">{{ __('Evidence (optional)') }}</label>
    <div class="mt-2 flex items-center gap-3 rounded-lg border border-dashed border-input bg-muted/30 px-3 py-3">
        <x-ui.icon-chip icon="file-text" size="sm" tone="gray" class="max-sm:hidden" />
        <input
            id="{{ $inputId }}"
            type="file"
            accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
            aria-describedby="{{ $inputId }}-hint"
            class="block w-full min-w-0 text-sm text-foreground file:mr-3 file:cursor-pointer file:rounded-md file:border file:border-border file:bg-background file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-foreground file:shadow-xs hover:file:bg-accent"
            wire:model="{{ $field }}"
        >
    </div>
    <p id="{{ $inputId }}-hint" class="mt-2 text-sm text-muted-foreground">{{ __('PDF, JPG or PNG, at most :size MB.', ['size' => app(\App\Services\Requests\Evidence::class)->maxMegabytes()]) }}</p>
    <p class="mt-1.5 flex items-center gap-2 text-sm text-muted-foreground" wire:loading.flex wire:target="{{ $field }}"><x-ui.spinner />{{ __('Uploading…') }}</p>
    <x-ui.error :for="$field" :id="$inputId.'-error'" />
</div>
