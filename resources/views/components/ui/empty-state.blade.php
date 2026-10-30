@props(['title', 'description' => null])

<div {{ $attributes->class('px-4 py-10 sm:px-5') }}>
    <p class="text-sm font-semibold text-ink">{{ $title }}</p>

    @if ($description)
        <p class="mt-1 max-w-prose text-sm text-muted">{{ $description }}</p>
    @endif

    @if (! $slot->isEmpty())
        <div class="mt-3">{{ $slot }}</div>
    @endif
</div>
