{{-- The rules of Password::defaults() (AppServiceProvider: min 12, mixed case, numbers), as a hint linked to the
     field by aria-describedby. Shown as text only: the server decides. --}}
<div id="{{ $id }}" class="rounded-md border border-border bg-muted/50 px-3 py-2.5 text-sm text-muted-foreground">
    <p class="font-medium text-foreground">{{ __('Your password needs:') }}</p>
    <ul class="mt-1.5 grid list-disc gap-1 ps-5">
        <li>{{ __('at least 12 characters') }}</li>
        <li>{{ __('uppercase and lowercase letters') }}</li>
        <li>{{ __('at least one digit') }}</li>
    </ul>
</div>
