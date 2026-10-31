<div>
    <label for="evidence" class="block text-sm font-medium text-ink-soft">Evidence (optional)</label>

    <input
        id="evidence"
        name="evidence"
        type="file"
        accept="{{ $accept }}"
        aria-describedby="evidence-hint @error('evidence') evidence-error @enderror"
        class="mt-1.5 block w-full text-sm text-ink-soft file:mr-3 file:cursor-pointer file:rounded-sm file:border file:border-rule-strong/60 file:bg-surface file:px-3 file:py-1 file:text-sm file:font-medium file:text-ink"
    >

    <p id="evidence-hint" class="mt-1 text-xs text-muted">PDF, JPG or PNG, up to {{ round(config('requests.evidence_max_kb') / 1024, 1) }} MB. Choose it again if the form comes back with an error.</p>

    <x-ui.error for="evidence" id="evidence-error" />
</div>
