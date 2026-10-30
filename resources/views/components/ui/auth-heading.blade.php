@props(['title'])

<div class="mb-5">
    <h1 class="text-xl font-semibold tracking-tight text-ink">{{ $title }}</h1>

    @if (! $slot->isEmpty())
        <p class="mt-1.5 text-sm text-ink-soft">{{ $slot }}</p>
    @endif
</div>
