@props(['title'])

<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $title }}</h1>

    @if (! $slot->isEmpty())
        <p class="mt-2 text-sm text-ink-soft">{{ $slot }}</p>
    @endif
</div>
